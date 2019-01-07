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
   * Routes to a machine specific http proxy function.
   */
  public function dockerProxyCreate() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $this->setDnsProxyMac();
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual dns boot will be required.");
    }
  }

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
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
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
   * Mac specific docker boot process.
   */
  protected function setMacBoot() {
    $this->setConfig();
    // Boot the Docker Machine.
    $this->io()->title('Ballast Startup');
    if (!$this->isDockerRunning()) {
      $root = $this->config->getProjectRoot();
      // Set the default to the parent of the project folder.
      $dir = dirname($root);
      $folder = $this->io()
        ->ask('What is the path to your docker sites folder?',
          $dir);
      $dockerResult = $this->taskExec('open -a Docker')
        ->printOutput(FALSE);
      $nfsResult = $this->setMacNfsConfig($folder);
      if (
        $dockerResult instanceof Result
        && $nfsResult instanceof Result
        && $dockerResult->wasSuccessful()
        && $nfsResult->wasSuccessful()
      ) {
        $this->io()->success('Ballast is ready to host projects.');
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
   * Setup the http-proxy service with dns for macOS.
   *
   * @see https://hub.docker.com/r/jwilder/nginx-proxy/
   */
  protected function setDnsProxyMac() {
    $this->setConfig();
    if ($this->isDockerRunning()) {
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
   * @param string $folderPath
   *   The path to the folder to be exported by NFS.
   *
   * @return \Robo\Result
   *   The result of the config tasks.
   */
  protected function setMacNfsConfig($folderPath) {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    $collection = $this->collectionBuilder();
    // Configure global nfs options.
    $nfsSetting = 'nfs.server.mount.require_resv_port = 0';
    if (file_exists('/etc/nfs.conf')) {
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
    if (strpos(file_get_contents("$root/setup/docker/nfs.conf"), $nfsSetting) === FALSE) {
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
      $this->io()->note('Ballast NFS setting is already present in /etc/nfs.conf');
      $collection->addTask(
        $this->taskFilesystemStack()->remove("$root/setup/docker/nfs.conf")
      );
    }
    // Export the given folder/directory.
    $user = getmyuid();
    $group = getmygid();
    $export = "$folderPath -alldirs -mapall=$user:$group localhost";
    if (file_exists('/etc/exports')) {
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
    if (strpos(file_get_contents("$root/setup/docker/exports"), $folderPath) === FALSE) {
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
      $collection->addTask(
        $this->taskFilesystemStack()->remove("$root/setup/docker/exports")
      );
    }
    return $collection->run();
  }

}
