<?php

namespace Omnibus\Harness;

use Omnibus\Exception\CarrierException;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\GatewayFactoryInterface;
use Omnibus\GatewayInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Registry;
use Omnibus\Request;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The console that exercises every carrier with the keys in .env:
 * gateways, rate, ship, track, pickup, slip, cancel.
 */
final class Console
{
    /** @var array<string, array{factory: string, needs: list<string>, options: array<string, mixed>}> */
    private array $config;

    /** @var array<string, GatewayFactoryInterface> */
    private array $factories = [];

    private Registry $registry;

    private function __construct()
    {
        $this->config = require __DIR__.'/../config/gateways.php';
        $http = HttpClient::create();
        foreach (require __DIR__.'/../plugins.php' as $ns) {
            $class = "Omnibus\\$ns\\{$ns}GatewayFactory";
            if (class_exists($class)) {
                $factory = new $class($http);
                $this->factories[$factory->getName()] = $factory;
            }
        }
        $configured = array_filter($this->config, static fn (array $g) => !array_filter($g['needs'], static fn (string $key) => false === getenv($key) || '' === getenv($key)));
        $this->registry = new Registry($this->factories, array_map(static fn (array $g) => ['factory' => $g['factory'], 'options' => array_filter($g['options'], static fn ($v) => null !== $v)], $configured));
    }

    public static function create(): Application
    {
        $self = new self();
        $app = new Application('omnibus', '2.x');
        $app->addCommand($self->command('gateways', 'Which carriers are installed and which are configured from .env', [], fn ($in, $out) => $self->gateways($out)));
        $app->addCommand($self->command('rate', 'The carrier\'s rates for a parcel', [new InputArgument('gateway', InputArgument::REQUIRED)], fn ($in, $out) => $self->rate($in, $out), true));
        $app->addCommand($self->command('ship', 'A shipment booked, its label saved under labels/', [new InputArgument('gateway', InputArgument::REQUIRED), new InputOption('service', 's', InputOption::VALUE_REQUIRED, 'The carrier\'s service code'), new InputOption('pickup-point', 'p', InputOption::VALUE_REQUIRED, 'A pickup point id'), new InputOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A shipment option, name=value')], fn ($in, $out) => $self->ship($in, $out), true));
        $app->addCommand($self->command('track', 'Where a parcel is', [new InputArgument('gateway', InputArgument::REQUIRED), new InputArgument('number', InputArgument::REQUIRED), new InputOption('locale', 'l', InputOption::VALUE_REQUIRED, '', 'fr')], fn ($in, $out) => $self->track($in, $out)));
        $app->addCommand($self->command('pickup', 'Pickup points near the recipient', [new InputArgument('gateway', InputArgument::REQUIRED), new InputOption('limit', null, InputOption::VALUE_REQUIRED, '', 10)], fn ($in, $out) => $self->pickup($in, $out), true));
        $app->addCommand($self->command('slip', 'A label fetched again', [new InputArgument('gateway', InputArgument::REQUIRED), new InputArgument('number', InputArgument::REQUIRED)], fn ($in, $out) => $self->slip($in, $out)));
        $app->addCommand($self->command('cancel', 'A shipment cancelled', [new InputArgument('gateway', InputArgument::REQUIRED), new InputArgument('number', InputArgument::REQUIRED)], fn ($in, $out) => $self->cancel($in, $out)));

        return $app;
    }

    /** @param list<InputArgument|InputOption> $definition */
    private function command(string $name, string $description, array $definition, \Closure $code, bool $addresses = false): Command
    {
        $command = new Command($name);
        $command->setDescription($description)->setDefinition($definition);
        if ($addresses) {
            foreach (['from', 'to'] as $side) {
                foreach (['name', 'company', 'street', 'postcode', 'city', 'country', 'email', 'phone'] as $field) {
                    $command->addOption("$side-$field", null, InputOption::VALUE_REQUIRED, "The ".('from' === $side ? 'sender' : 'recipient')."'s $field (default: HARNESS_".strtoupper('from' === $side ? 'sender' : 'recipient')."_".strtoupper($field).")");
                }
            }
            $command->addOption('weight', 'w', InputOption::VALUE_REQUIRED, 'Grams', 800);
            $command->addOption('size', null, InputOption::VALUE_REQUIRED, 'LxWxH in cm', '30x20x10');
            $command->addOption('value', null, InputOption::VALUE_REQUIRED, 'Minor units', 2500);
            $command->addOption('currency', null, InputOption::VALUE_REQUIRED, '', 'EUR');
            $command->addOption('reference', 'r', InputOption::VALUE_REQUIRED, '', 'OMNIBUS-'.date('ymd-His'));
        }
        $command->setCode(function (InputInterface $in, OutputInterface $out) use ($code): int {
            try {
                return (int) ($code($in, $out) ?? Command::SUCCESS);
            } catch (CarrierException $e) {
                $out->writeln(sprintf('<error>%s</error>%s', $e->getMessage(), $e->carrierCode ? " (code $e->carrierCode)" : ''));

                return Command::FAILURE;
            } catch (InvalidConfigException $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::INVALID;
            }
        });

        return $command;
    }

    private function gateways(OutputInterface $out): void
    {
        $requests = ['rate' => Request\Rating::class, 'ship' => Request\Shipping::class, 'track' => Request\Tracking::class, 'pickup' => Request\Pickup::class, 'slip' => Request\GetSlip::class, 'cancel' => Request\Cancel::class];
        $table = new Table($out);
        $table->setHeaders(['Gateway', 'Factory', 'Installed', 'Configured', 'Does']);
        foreach ($this->config as $name => $gateway) {
            $installed = isset($this->factories[$gateway['factory']]);
            $missing = array_filter($gateway['needs'], static fn (string $key) => false === getenv($key) || '' === getenv($key));
            $does = '';
            if ($installed && !$missing) {
                $g = $this->registry->get($name);
                $does = implode(' ', array_keys(array_filter($requests, static fn (string $class) => $g->supports($class))));
            }
            $table->addRow([$name, $gateway['factory'], $installed ? '<info>yes</info>' : '<comment>no</comment>', $missing ? '<comment>needs '.implode(', ', $missing).'</comment>' : ($installed ? '<info>yes</info>' : ''), $does]);
        }
        $table->render();
    }

    private function rate(InputInterface $in, OutputInterface $out): void
    {
        $rates = $this->gateway($in)->rate($this->shipment($in));
        if (!$rates) {
            $out->writeln('<comment>No rate offered.</comment>');

            return;
        }
        $table = new Table($out);
        $table->setHeaders(['Service', 'Label', 'Amount', 'Days', 'To pickup point']);
        foreach ($rates as $rate) {
            $table->addRow([$rate->service, $rate->label, number_format($rate->amount / 100, 2).' '.$rate->currency, $rate->days ?? '', $rate->toPickupPoint ? 'yes' : '']);
        }
        $table->render();
    }

    private function ship(InputInterface $in, OutputInterface $out): void
    {
        $label = $this->gateway($in)->ship($this->shipment($in));
        $this->label($label, $out);
    }

    private function slip(InputInterface $in, OutputInterface $out): void
    {
        $this->label($this->gateway($in)->slip($in->getArgument('number')), $out);
    }

    private function label(Label $label, OutputInterface $out): void
    {
        $out->writeln(sprintf('<info>%s</info> %s', $label->trackingNumber, $label->trackingUrl ?? ''));
        if (null !== $label->url) {
            $out->writeln('Document: '.$label->url);
        }
        if (null !== $label->content) {
            $ext = match ($label->format) { Label::PDF => 'pdf', Label::ZPL => 'zpl', 'image/gif' => 'gif', 'image/png' => 'png', 'application/json' => 'json', default => 'xml' };
            $file = '/harness/labels/'.$label->carrier.'-'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $label->trackingNumber).'.'.$ext;
            file_put_contents($file, $label->content);
            $out->writeln('Label saved: labels/'.basename($file));
        }
    }

    private function track(InputInterface $in, OutputInterface $out): void
    {
        $tracking = $this->gateway($in)->track($in->getArgument('number'), $in->getOption('locale'));
        $out->writeln(sprintf('<info>%s</info> %s', $tracking->trackingNumber, $tracking->status->value));
        $table = new Table($out);
        $table->setHeaders(['At', 'Status', 'Description', 'Location', 'Code']);
        foreach ($tracking->events as $event) {
            $table->addRow([$event->at->format('Y-m-d H:i'), $event->status->value, $event->description, $event->location ?? '', $event->code ?? '']);
        }
        $table->render();
    }

    private function pickup(InputInterface $in, OutputInterface $out): void
    {
        $points = $this->gateway($in)->pickupPoints($this->address($in, 'to'), (int) $in->getOption('limit'), $this->parcel($in));
        $table = new Table($out);
        $table->setHeaders(['Id', 'Name', 'Address', 'Distance', 'Monday']);
        foreach ($points as $point) {
            $table->addRow([$point->id, $point->name, implode(', ', array_filter([$point->address->line(0), $point->address->postcode.' '.$point->address->city])), null === $point->distance ? '' : $point->distance.' m', implode(' ', array_map(static fn ($h) => "$h[0]-$h[1]", $point->openingHours[1] ?? []))]);
        }
        $table->render();
    }

    private function cancel(InputInterface $in, OutputInterface $out): void
    {
        $out->writeln($this->gateway($in)->cancel($in->getArgument('number')) ? '<info>Cancelled.</info>' : '<comment>Not cancelled.</comment>');
    }

    private function gateway(InputInterface $in): GatewayInterface
    {
        return $this->registry->get($in->getArgument('gateway'));
    }

    private function shipment(InputInterface $in): Shipment
    {
        $options = [];
        foreach ((array) ($in->hasOption('option') ? $in->getOption('option') : []) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '1');
            $options[$k] = \in_array($v, ['true', 'false'], true) ? 'true' === $v : $v;
        }

        return new Shipment($this->address($in, 'from'), $this->address($in, 'to'), [$this->parcel($in)], $in->hasOption('service') ? $in->getOption('service') : null, $in->hasOption('pickup-point') ? $in->getOption('pickup-point') : null, $in->getOption('reference'), $options);
    }

    private function parcel(InputInterface $in): Parcel
    {
        [$l, $w, $h] = array_map('intval', array_pad(explode('x', (string) $in->getOption('size')), 3, 0));

        return new Parcel((int) $in->getOption('weight'), $l ?: null, $w ?: null, $h ?: null, (int) $in->getOption('value'), strtoupper((string) $in->getOption('currency')));
    }

    private function address(InputInterface $in, string $side): Address
    {
        $prefix = 'HARNESS_'.('from' === $side ? 'SENDER' : 'RECIPIENT').'_';
        $get = static fn (string $field): ?string => $in->getOption("$side-$field") ?? ((false !== ($v = getenv($prefix.strtoupper($field))) && '' !== $v) ? $v : null);
        $street = array_values(array_filter(array_map('trim', explode('|', (string) $get('street')))));

        return new Address((string) $get('name'), $street ?: [''], (string) $get('postcode'), (string) $get('city'), (string) ($get('country') ?? 'FR'), $get('company'), $get('email'), $get('phone'));
    }
}
