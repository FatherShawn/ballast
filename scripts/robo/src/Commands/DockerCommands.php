<?php

namespace Ballast\Commands;

use Ballast\Utilities\Config;
use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;

/**
 * Robo commands that manage docker interactions.
 *
 * @package Ballast\Commands
 */
class DockerCommands extends Tasks {

  use FrontEndTrait;

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Entry point command to launch the docker-compose process.
   *
   * Routes to a machine specific compose function.
   */
  public function dockerCompose() {
    $this->setConfig();
    $launched = FALSE;
    switch (php_uname('s')) {
      case 'Darwin':
        $drupalRoot = $this->config->getDrupalRoot();
        if (!file_exists("$drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` before you run this Drupal site.');
        }
        elseif ($this->isDockerRunning()) {
          // The docker machine is installed and running.
          $launched = $this->setDockerComposeMac();
        }
        else {
          // The docker machine is installed but not running.
          $this->io()
            ->error('You must start the docker service using `ahoy cast-off`');
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual docker startup will be required.");
    }
    if ($launched) {
      $this->io()
        ->text('Please stand by while the front end tools initialize.');
      if ($this->getFrontEndStatus(TRUE)) {
        $this->io()->text('Front end tools are ready.');
        $this->setClearFrontEndFlags();
      }
      else {
        $this->io()
          ->caution('The wait timer expired waiting for front end tools to report readiness.');
      }
      $this->io()
        ->success('The site can now be reached at ' . $this->config->get('site_shortname') . '.dpulp/');
    }
  }

  /**
   * Prepare this Mac to move from Ballast 1.0 to 1.1 (Docker for Mac).
   */
  public function macConvert() {
    $home = getenv('HOME');
    if (file_exists("$home/.docker/machine/machines/dp-docker")) {
      $removeDockerMachine = $this->io()->confirm('Ballast 1.0 docker machine detected.  Remove and upgrade to Ballast 1.1', FALSE);
      if ($removeDockerMachine) {
        $this->io()->warning('All sites will have to be re-installed, or rebuilt from a remote.  The upgrade will destroy all persistent container data including databases.');
        $confirmed = $this->io()->confirm('I understand and am ready to upgrade.', FALSE);
        if ($confirmed) {
          $this->setDockerMachineRemoved();
        }
        $this->io()->note('If the conversion ran without issue, run `composer install` now to get Docker for Mac.  If you already have Docker for Mac, open it and verify that it has installed `docker` and `docker-compose` commands.');
      }
    }
  }

  /**
   * Entry point command for the boot process.
   *
   * Routes to a machine specific boot function.
   *
   * @aliases boot
   */
  public function bootDocker() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        if (!file_exists($this->config->getDrupalRoot() . '/core')) {
          $this->io()
            ->error('You must run `composer install` before you run this Drupal site.');
        }
        else {
          $this->setMacBoot();
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual boot will be required.");
    }
  }

  /**
   * Start DNS service to resolve containers.
   */
  public function bootDns() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        if ($this->setMacDnsmasq()) {
          /* dnsmasq running - put the mac resolver file in place. */
          $this->setResolverFile();
          $this->io()->success('Ballast DNS service started.');
          if ($this->confirm('Would you also like to launch the site created by this project?')) {
            $this->dockerCompose();
          }
          return;
        }
        $this->io()->error('Unable to create dns container.');
        return;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  DNS not initiated.");
    }
  }

  /**
   * Prints the database connection info for use in SQL clients.
   */
  public function connectSql() {
    $port = $this->getSqlPort();
    $this->io()->title('Database Info');
    $this->io()->text("Docker for Mac maps container ports to localhost");
    $this->io()->text("Connect to port: $port");
    $this->io()->text("Username, password, and database are all 'drupal'");
    $this->io()->note("Both the ip and port can vary between re-boots");
  }

  /**
   * All the methods that follow are protected helper methods.
   */

  /**
   * Singleton manager for Ballast\Utilities\Config.
   */
  protected function setConfig() {
    if (!$this->config instanceof Config) {
      $this->config = new Config();
    }
  }

  /**
   * Gets the packages installed using Homebrew.
   *
   * Duplicate code from SetupCommands.  Remove in next version.
   *
   * @return array
   *   Associative array keyed by package short name.
   */
  protected function getBrewedComponents() {
    $this->io()->comment('Getting the packages installed with Homebrew');
    $result = $this->taskExec('brew info --json=v1 --installed')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $this->io()->newLine();
    if ($result instanceof Result) {
      $rawJson = json_decode($result->getMessage(), 'assoc');
      $parsed = [];
      foreach ($rawJson as $package) {
        $parsed[$package['name']] = $package;
        unset($parsed[$package['name']]['name']);
      }
      return $parsed;
    }
    throw new UnexpectedValueException("taskExec() failed to return a valid Result object in getBrewedComponents()");
  }

  /**
   * Helper function to remove outdated docker-machine.
   *
   * Remove in next version.
   */
  protected function setDockerMachineRemoved() {
    $home = getenv('HOME');
    $directory = "$home/.docker/machine/machines/dp-docker";
    $remove = [
      'docker',
      'docker-compose',
      'docker-machine',
    ];
    $brewed = $this->getBrewedComponents();
    $removeList = [];
    foreach ($remove as $package) {
      if (isset($brewed[$package])) {
        // Installed.
        $removeList[] = $package;
      }
    }
    $removeList = implode(' ', $removeList);
    $this->io()->text("The following packages need to be unistalled: $removeList");
    $removed = $this->taskExec("brew uninstall $removeList")
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($removed instanceof Result && $removed->wasSuccessful()) {
      $this->io()->success('Packages are uninstalled.');
    }
    $deleteMachine = $this->taskDeleteDir($directory)->run();
    if ($deleteMachine instanceof Result && $deleteMachine->wasSuccessful()) {
      $this->io()->success('dp-docker machine data is removed.');
    }
  }

  /**
   * Mac specific docker boot process.
   */
  protected function setMacBoot() {
    $this->setConfig();
    // Boot the Docker Machine.
    $this->io()->title('Ballast Startup');
    if (!$this->isDockerRunning()) {
      $dockerResult = $this->taskExec('open -a Docker')
        ->printOutput(FALSE);
      if ($dockerResult instanceof Result && $dockerResult->wasSuccessful()) {
        $this->setDnsProxyMac();
      }
      else {
        $this->io()->error('Unable to start Docker for Mac');
      }
      $nfsResult = $this->setMacNfsConfig();
      if (
        $dockerResult instanceof Result
        && $nfsResult instanceof Result
        && $dockerResult->wasSuccessful()
        && $nfsResult->wasSuccessful()
      ) {
        $this->io()->success('Ballast is ready to host projects.');
      }
      else {
        $this->io()
          ->error('NFS setup check failed.');
      }
    }
    else {
      $this->io()
        ->note('Docker is already running.  Assuming NFS is already prepared by Ballast.');
    }
  }

  /**
   * Place or update the dns resolver file.
   */
  protected function setResolverFile() {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    if (!file_exists('/etc/resolver/dpulp')) {
      $collection = $this->collectionBuilder();
      $collection->addTask(
        $this->taskExec('cp ' . "$root/setup/dns/dpulp-template $root/setup/dns/dpulp")
      )->rollback(
        $this->taskExec('rm -f' . "$root/setup/dns/dpulp")
      );
      if (!file_exists('/etc/resolver')) {
        $collection->addTask(
          $this->taskExec('sudo mkdir /etc/resolver')
        );
      }
      $collection->addTask(
        $this->taskExecStack()
          ->exec("sudo mv $root/setup/dns/dpulp /etc/resolver")
          ->exec('sudo chown root:wheel /etc/resolver/dpulp')
      );
      $collection->run();
    }
  }

  /**
   * Helper function that checks for the existance of proxynet.
   *
   * @return bool
   *   The docker network proxynet exists.
   */
  protected function getProxyConfigured() {
    $proxynetFound = FALSE;
    $result = $this->taskExec("docker network inspect proxynet")
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $inspection = json_decode($result->getMessage(), 'assoc');
      if (!empty($inspection) && is_array($inspection)) {
        foreach ($inspection as $network) {
          if (isset($network['Name']) && $network['Name'] == 'proxynet') {
            // The network exists.
            $proxynetFound = TRUE;
          }
        }
      }
    }
    return $proxynetFound;
  }

  /**
   * Setup the http-proxy service with dns for macOS.
   *
   * @see https://hub.docker.com/r/jwilder/nginx-proxy/
   */
  protected function setDnsProxyMac() {
    $this->setConfig();
    if ($this->isDockerRunning()) {
      if ($this->getProxyConfigured()) {
        $this->io()
          ->note('HTTP Proxy network found. HTTP Proxy and .dpulp domain resolution previously created.');
        return;
      }
      $this->io()->title('Setup HTTP Proxy and .dpulp domain resolution.');
      // Boot the DNS service.
      $boot_task = $this->collectionBuilder();
      $boot_task->addTask(
        $this->taskExec("docker network create proxynet")
      )->rollback(
        $this->taskExec("docker network prune")
      );
      $command = "docker run";
      $command .= ' -d -v /var/run/docker.sock:/tmp/docker.sock:ro';
      $command .= ' -p 80:80 --restart always --network proxynet';
      $command .= ' --name http-proxy digitalpulp/nginx-proxy';
      $boot_task->addTask(
        $this->taskExec($command)
      )->rollback(
        $this->taskExec("docker rm http-proxy")
      );
      $result = $boot_task->run();
      if ($result instanceof Result && $result->wasSuccessful()) {
        $this->io()->success('Proxy container is setup.');
      }
    }
    else {
      $this->io()
        ->error('Docker for Mac does not seem to be running.  Unable to setup dns service.');
    }
  }

  /**
   * Launches the dnsmasq container if it is not running.
   */
  protected function setMacDnsmasq() {
    $this->setConfig();
    if (!$this->isDockerRunning()) {
      $this->io()
        ->error('Unable to connect to docker.  Cannot launch dns container.');
      return;
    }
    $result = $this->taskExec("docker inspect dnsmasq")
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $inspection = json_decode($result->getMessage(), 'assoc');
      if (!empty($inspection) && is_array($inspection)) {
        // There should be only one.
        $dnsmasq = reset($inspection);
        if (isset($dnsmasq['State']['Running'])) {
          // The container exists.
          if ($dnsmasq['State']['Running']) {
            // And the container is already running.
            $this->io()->note('DNS service is already running.');
            return TRUE;
          }
          // The container is stopped - restart it.
          $this->say('Container exists but is stopped.');
          $this->taskExec("docker restart dnsmasq")
            ->printOutput(FALSE)
            ->printMetadata(FALSE)
            ->run();
        }
      }
    }
    // There is no dns container.
    $command = "docker run";
    $command .= ' -d --name dnsmasq';
    $command .= " --publish '53535:53/tcp' --publish '53535:53/udp'";
    $command .= ' --cap-add NET_ADMIN  andyshinn/dnsmasq:2.76';
    $command .= " --address=/dpulp/127.0.0.1";
    $result = $this->taskExec($command)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    return ($result instanceof Result && $result->wasSuccessful());
  }

  /**
   * Mac specific command to start docker-compose services.
   */
  protected function setDockerComposeMac() {
    $this->setConfig();
    if (!$this->isDockerRunning()) {
      // Docker is not running.
      $this->io()
        ->error('You must start the docker service using `ahoy cast-off`');
      return;
    }
    $root = $this->config->getProjectRoot();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$root/setup/docker/docker-compose-template",
          "$root/setup/docker/docker-compose.yml")
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$root/setup/docker/docker-compose.yml")
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{site_shortname}')
        ->to($this->config->get('site_shortname'))
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{site_theme_name}')
        ->to($this->config->get('site_theme_name'))
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{project_root}')
        ->to($root)
    );
    // Move into place or overwrite the docker-compose.yml.
    $collection->addTask(
      $this->taskFilesystemStack()
        ->rename("$root/setup/docker/docker-compose.yml",
          "$root/docker-compose.yml", TRUE)
    );
    $collection->run();
    $result = $this->taskExec("docker-compose up -d ")->run();
    return (isset($result) && $result->wasSuccessful());
  }

  /**
   * Is Docker running?
   *
   * @return bool
   *   Boolean value if we can get a docker response.
   */
  public function isDockerRunning() {
    $isInstalled = $this->taskExec('which -s docker')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if (!$isInstalled->wasSuccessful()) {
      $justInstalled = $this->io()
        ->confirm('The docker command was not found.  Was Docker for Mac just installed');
      if ($justInstalled) {
        $this->io()
          ->confirm('Docker for Mac needs to be launched the first time from the Finder. Enter "yes" once you have launched Docker for Mac for the first time', FALSE);
      }
      else {
        $this->io()->error('The docker command could not be found.');
        return $isInstalled->wasSuccessful();
      }
    }
    $result = $this->taskExec('docker ps')->run();
    return $result->wasSuccessful();
  }

  /**
   * Get the port exposed for sqlServer from the database service.
   *
   * @return mixed
   *   The port or null if it is not running.
   */
  protected function getSqlPort() {
    $this->setConfig();
    // Get the port string.
    $port = NULL;
    $result = $this->taskExec("docker-compose port database 3306")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $raw = $result->getMessage();
      $port = trim(substr($raw, strpos($raw, ':') + 1));
    }
    return $port;
  }

  /**
   * Helper method to configure mac NFS.
   *
   * @return \Robo\Result
   *   The result of the NFS tasks.
   */
  protected function setMacNfsConfig() {
    $root = $this->config->getProjectRoot();
    // Set the default to the parent of the project folder.
    $dir = dirname($root);
    $folderPath = $this->io()
      ->ask('What is the path to your docker sites folder?',
        $dir);
    $collection = $this->collectionBuilder();
    $nfsConfigPresent = FALSE;
    // Configure global nfs options.
    $nfsSetting = 'nfs.server.mount.require_resv_port = 0';
    $nfsConfigExists = file_exists('/etc/nfs.conf');
    if ($nfsConfigExists) {
      $nfsConfigPresent = !strpos(file_get_contents("/etc/nfs.conf"), $nfsSetting) === FALSE;
    }
    $needsNfsConfig = !($nfsConfigExists && $nfsConfigPresent);
    if ($needsNfsConfig) {
      if ($nfsConfigExists) {
        $collection->addTask(
          $this->taskFilesystemStack()
            ->copy('/etc/nfs.conf', "$root/setup/docker/nfs.conf")
        );
      }
      else {
        $collection->addTask(
          $this->taskFilesystemStack()
            ->touch("$root/setup/docker/nfs.conf")
        );
      }
      // $nfsConfigPresent is false if we are here.
      $collection->addTask(
        $this->taskWriteToFile("$root/setup/docker/nfs.conf")
          ->append(TRUE)
          ->line('# ballast-setup-start')
          ->line($nfsSetting)
          ->line('# ballast-setup-end')
      );
      $collection->addTask(
        $this->taskExecStack()
          ->exec("sudo mv $root/setup/docker/nfs.conf /etc/nfs.conf")
          ->exec('sudo chown root:wheel /etc/nfs.conf')
      );
    }
    else {
      $this->io()
        ->note('Ballast NFS is already setup.');
    }
    // Export the given folder/directory.
    $user = getmyuid();
    $group = getmygid();
    $exportConfigPresent = FALSE;
    $export = "$folderPath -alldirs -mapall=$user:$group localhost";
    $exportFileExists = file_exists('/etc/exports');
    if ($exportFileExists) {
      $exportConfigPresent = !strpos(file_get_contents("/etc/exports"), $folderPath) === FALSE;
    }
    $needsExportConfig = !($exportFileExists && $exportConfigPresent);
    if ($needsExportConfig) {
      if ($exportFileExists) {
        $collection->addTask(
          $this->taskFilesystemStack()
            ->copy('/etc/exports', "$root/setup/docker/exports")
        );
      }
      else {
        $collection->addTask(
          $this->taskFilesystemStack()
            ->touch("$root/setup/docker/exports")
        );
      }
      // $exportConfigPresent is false if we are here.
      $collection->addTask(
        $this->taskWriteToFile("$root/setup/docker/exports")
          ->append(TRUE)
          ->line('# ballast-setup-start')
          ->line($export)
          ->line('# ballast-setup-end')
      );
      $collection->addTask(
        $this->taskExecStack()
          ->exec("sudo mv $root/setup/docker/exports /etc/exports")
          ->exec('sudo chown root:wheel /etc/exports')
      );
    }
    else {
      $this->io()->note("$folderPath is already present in /etc/exports");
    }
    return $collection->run();
  }

}
