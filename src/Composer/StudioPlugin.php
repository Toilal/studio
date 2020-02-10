<?php

namespace Studio\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
use Composer\Repository\PathRepository;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Studio\Config\Config;

class StudioPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var StudioVersionGuesser
     */
    protected $studioVersionGuesser;

    /**
     * StudioPlugin constructor.
     *
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->studioVersionGuesser = new StudioVersionGuesser($this->composer->getConfig(), new ProcessExecutor($this->io), new \Composer\Semver\VersionParser());
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_UPDATE_CMD => [['registerStudioPackages', 512]],
            ScriptEvents::PRE_INSTALL_CMD => [['registerStudioPackages', 512]],
            InstallerEvents::POST_DEPENDENCIES_SOLVING => [['alterOperations', 256]],
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => [['alterRequest', 256]]
        ];
    }

    /**
     * Register all managed paths with Composer.
     *
     * This function configures Composer to treat all Studio-managed paths as local path repositories, so that packages
     * therein can be symlinked directly.
     *
     * PathRepository instances are hacked to guess version from studio.version file instead of CVS metadata,
     * defaulting to "dev-studio" if the file doesn't exists.
     *
     * The package will be symlink if hacked guess version match the version defined contraint from requesting package.
     */
    public function registerStudioPackages()
    {
        $repositoryManager = $this->composer->getRepositoryManager();
        $rootPackage = $this->composer->getPackage();

        $versionGuesserProperty = new \ReflectionProperty(PathRepository::class, "versionGuesser");
        $versionGuesserProperty->setAccessible(true);

        foreach ($this->getManagedPaths() as $path) {
            $resolvedPaths = $this->resolvePath($path);
            foreach ($resolvedPaths as $resolvedPath) {
                $package = $this->createPackageForPath($resolvedPath);
                if ($package == null || $package->getName() == $this->composer->getPackage()->getName()) {
                    continue;
                }

                $name = $package->getName();
                $repositoryConfig = [
                    "type" => "path",
                    "url" => $resolvedPath,
                    "options" => [
                        "symlink" => true
                    ]
                ];

                $config = $this->composer->getConfig();
                $config->merge(["repositories" =>
                    [$name => $repositoryConfig]
                ]);

                $pathRepository = new PathRepository($repositoryConfig, $this->io, $this->composer->getConfig());
                $versionGuesserProperty->setValue($pathRepository, $this->studioVersionGuesser);
                $repositoryManager->prependRepository($pathRepository);

                $packageConfig = $rootPackage->getConfig();
                if (!array_key_exists("repositories", $packageConfig)) {
                    $packageRepositories = [];
                } else {
                    $packageRepositories = $packageConfig["repositories"];
                }

                $packageRepositories["$name"] = $repositoryConfig;
                $packageConfig["repositories"] = $packageRepositories;

                $repositories = $rootPackage->getRepositories();
                if ($repositories == null) {
                    $repositories["$name"] = $repositoryConfig;
                }
                $rootPackage->setRepositories($repositories);
            }
        }
    }

    private function alterStudioPackageDependency(PackageInterface $package, PackageInterface $studioPackage) {
        if ($package instanceof CompletePackage) {
            $package->setSourceType(null);
            $package->setSourceUrl(null);
        }

        $package->setDistType($studioPackage->getDistType());
        $package->setDistUrl($studioPackage->getDistUrl());
        $package->setDistReference($studioPackage->getDistReference());

        $package->replaceVersion($studioPackage->getVersion(), $studioPackage->getPrettyVersion());
    }

    private function buildPathPackages() {
        $pathPackages = [];

        foreach ($this->getManagedPaths() as $path) {
            $resolvedPaths = $this->resolvePath($path);
            foreach ($resolvedPaths as $resolvedPath) {
                $package = $this->createPackageForPath($resolvedPath);
                if ($package == null || $package->getName() == $this->composer->getPackage()->getName()) {
                    continue;
                }

                $pathPackages[$package->getName()] = $package;
            }
        }

        return $pathPackages;
    }

    public function alterRequest(InstallerEvent $event) {
//        $pathPackages = $this->buildPathPackages();
//
//        $alteredJobs = [];
//
//        foreach ($event->getRequest()->getJobs() as $i => $job) {
//            if (array_key_exists("cmd", $job) && in_array($job["cmd"], ["install"])) {
//                $packageName = $job["packageName"];
//
//                if (array_key_exists($packageName, $pathPackages)) {
//                    /** @var PackageInterface $studioPackage */
//                    $studioPackage = $pathPackages[$packageName];
//                    $version = $studioPackage->getVersion();
//                    $constraint = new Constraint("=", "$version");
//                    $job["constraint"] = $constraint;
//                }
//            }
//
//            $alteredJobs[] = $job;
//        }
//
//        // Hack to modify request internal jobs.
//        $jobsProperty = new \ReflectionProperty(Request::class, "jobs");
//        $jobsProperty->setAccessible(true);
//        $jobsProperty->setValue($event->getRequest(), $alteredJobs);
    }

    public function alterOperations(InstallerEvent $event) {
        $pathPackages = $this->buildPathPackages();

        foreach ($this->getManagedPaths() as $path) {
            $resolvedPaths = $this->resolvePath($path);
            foreach ($resolvedPaths as $resolvedPath) {
                $package = $this->createPackageForPath($resolvedPath);
                if ($package == null || $package->getName() == $this->composer->getPackage()->getName()) {
                    continue;
                }

                $pathPackages[$package->getName()] = $package;
            }
        }
        foreach ($event->getOperations() as $operation) {
            if ($operation instanceof InstallOperation || $operation instanceof UninstallOperation) {
                $package = $operation->getPackage();
                if (array_key_exists($package->getName(), $pathPackages)) {
                    /** @var CompletePackage $studioPackage */
                    $studioPackage = $pathPackages[$package->getName()];
                    $this->alterStudioPackageDependency($package, $studioPackage);
                }
            } elseif ($operation instanceof UpdateOperation) {
                $package = $operation->getInitialPackage();
                if (array_key_exists($package->getName(), $pathPackages)) {
                    /** @var CompletePackage $studioPackage */
                    $studioPackage = $pathPackages[$package->getName()];
                    $this->alterStudioPackageDependency($package, $studioPackage);
                }

                $package = $operation->getTargetPackage();
                if (array_key_exists($package->getName(), $pathPackages)) {
                    /** @var CompletePackage $studioPackage */
                    $studioPackage = $pathPackages[$package->getName()];
                    $this->alterStudioPackageDependency($package, $studioPackage);
                }
            }
        }
    }

    /**
     * Creates package from given path
     *
     * @param string $path
     * @return PackageInterface
     */
    private function createPackageForPath($path)
    {
        $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';

       if (is_readable($composerJson)) {
           $json = (new JsonFile($composerJson))->read();
           $version = $this->studioVersionGuesser->guessVersion($json, $path);
           $json['version'] = $version['version'];

            // branch alias won't work, otherwise the ArrayLoader::load won't return an instance of CompletePackage
            unset($json['extra']['branch-alias']);

            $loader = new ArrayLoader();
            /** @var PackageInterface $package */
            $package = $loader->load($json);

           if ($package instanceof CompletePackage) {
               $package->setSourceType(null);
               $package->setSourceUrl(null);
           }

            $package->setDistType("path");
            $package->setDistUrl($path);
            $package->setDistReference(null);

            return $package;
        }

        return NULL;
    }


    /**
     * Resolve path with glob to an array of existing paths.
     *
     * @param string $path
     * @return string[]
     */
    private function resolvePath($path)
    {
        /** @var string[] $paths */
        $paths = [];

        $realPaths = glob($path);
        foreach ($realPaths as $realPath) {
            if (!in_array($realPath, $paths)) {
                $paths[] = $realPath;
            }
        }

        return $paths;
    }

    /**
     * Get the list of paths that are being managed by Studio.
     *
     * @return array
     */
    private function getManagedPaths()
    {
        $rootPackage = $this->composer->getPackage();

        $targetDir = realpath($rootPackage->getTargetDir());
        $config = Config::make($targetDir . DIRECTORY_SEPARATOR . 'studio.json');

        return $config->getPaths();
    }
}
