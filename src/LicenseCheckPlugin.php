<?php

declare(strict_types=1);

namespace Metasyntactical\Composer\LicenseCheck;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\CompletePackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\Capability\Capability as CapabilityInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable as CapableInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Metasyntactical\Composer\LicenseCheck\Command\CommandProvider;

final class LicenseCheckPlugin implements PluginInterface, CapableInterface, EventSubscriberInterface
{
    public const PLUGIN_PACKAGE_NAME = 'metasyntactical/composer-plugin-license-check';

    private Composer $composer;

    private IOInterface $io;

    private array $licenseWhitelist = [];

    private array $licenseBlacklist = [];

    private array $whitelistedPackages = [];

    public function __construct()
    {
        $this->io = new NullIO();
        $this->composer = new Composer();
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $rootPackage = $composer->getPackage();

        $config = $this->getConfig($rootPackage);

        $this->licenseWhitelist = $config->whitelist();
        $this->licenseBlacklist = $config->blacklist();
        $this->whitelistedPackages = $config->whitelistedPackages();
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @psalm-return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => [['handleEventAndOutputDebugMessage', 101]],
            PackageEvents::POST_PACKAGE_INSTALL => [['handleEventAndCheckLicense', 100]],
            PackageEvents::POST_PACKAGE_UPDATE => [['handleEventAndCheckLicense', 100]],
        ];
    }

    public function handleEventAndOutputDebugMessage(CommandEvent $event): void
    {
        if (!in_array($event->getCommandName(), ['install', 'update'], true)) {
            return;
        }
        if (!$this->io->isVerbose()) {
            return;
        }

        $this->io->writeError('<info>The Metasyntactical LicenseCheck Plugin has been enabled.</info>');
    }

    public function handleEventAndCheckLicense(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $operationType = $operation->getOperationType();

        switch ($operationType) {
            case InstallOperation::TYPE:
                /** @var InstallOperation $operation */
                $package = $operation->getPackage();

                if ($package->getName() === self::PLUGIN_PACKAGE_NAME) {
                    $this->composer->getEventDispatcher()->addSubscriber($this);
                    if ($event->getIO()->isVerbose()) {
                        $event->getIO()->writeError('<info>The Metasyntactical LicenseCheck Plugin has been enabled.</info>');
                    }
                }
                break;
            case UpdateOperation::TYPE:
                /** @var UpdateOperation $operation */
                $package = $operation->getTargetPackage();
                break;
            default:
                return;
        }

        if ($package->getName() === self::PLUGIN_PACKAGE_NAME) {
            // Skip license check. It is assumed that the licence checker itself is
            // added to the dependencies on purpose and therefore the license of the
            // license checker is provided with (MIT) is accepted.
            return;
        }

        $packageLicenses = [];
        if (is_a($package, CompletePackageInterface::class)) {
            $packageLicenses = $package->getLicense();
        }

        $allowedToUse = true;
        if ($this->licenseBlacklist) {
            $allowedToUse = !array_intersect($packageLicenses, $this->licenseBlacklist);
        }
        if ($allowedToUse && $this->licenseWhitelist) {
            $allowedToUse = (bool) array_intersect($packageLicenses, $this->licenseWhitelist);
        }

        if ($package->getName() === 'metasyntactical/composer-plugin-license-check') {
            $allowedToUse = true;
        }

        if (!$allowedToUse) {
            if (!array_key_exists($package->getPrettyName(), $this->whitelistedPackages)) {
                throw new LicenseNotAllowedException(
                    sprintf(
                        'ERROR: Licenses "%s" of package "%s" are not allowed to be used in the project. Installation failed.',
                        implode(', ', $packageLicenses),
                        $package->getPrettyName()
                    )
                );
            }
            $this->io->writeError(
                sprintf(
                    'WARNING: Licenses "%s" of package "%s" are not allowed to be used in the project but the package has been whitelisted.',
                    implode(', ', $packageLicenses),
                    $package->getPrettyName()
                )
            );
        }
    }

    private function getConfig(RootPackageInterface $package): ComposerConfig
    {
        $config = $package->getExtra()[self::PLUGIN_PACKAGE_NAME] ?? [];
        assert(is_array($config));
        /** @psalm-var array{whitelist?: list<mixed>, blacklist?: list<mixed>, whitelisted-packages?: list<mixed>} $config */

        return new ComposerConfig($config);
    }
}
