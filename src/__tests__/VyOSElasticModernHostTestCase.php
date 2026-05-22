<?php

final class VyOSElasticModernHostTestCase
  extends PhutilTestCase {

  public function testDisplayName() {
    $engine = new VyOSElasticModernFulltextStorageEngine();
    $host = new VyOSElasticModernHost($engine);
    $this->assertEqual(
      'Elasticsearch (modern)',
      (string)$host->getDisplayName());
  }

  public function testConfigDefaults() {
    $engine = new VyOSElasticModernFulltextStorageEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array());
    $this->assertEqual('http', $host->getProtocol());
    $this->assertEqual('phabricator/', $host->getPath());
    $this->assertEqual(7, $host->getVersion());
  }

  public function testConfigOverrides() {
    $engine = new VyOSElasticModernFulltextStorageEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array(
      'host'     => 'es.example.com',
      'port'     => 9200,
      'protocol' => 'https',
      'path'     => '/myphorge',
      'version'  => 8,
    ));
    $this->assertEqual('es.example.com', $host->getHost());
    $this->assertEqual(9200, $host->getPort());
    $this->assertEqual('https', $host->getProtocol());
    $this->assertEqual('/myphorge', $host->getPath());
    $this->assertEqual(8, $host->getVersion());
  }

  public function testGetURI() {
    $engine = new VyOSElasticModernFulltextStorageEngine();
    $host = new VyOSElasticModernHost($engine);
    $host->setConfig(array(
      'host'     => 'es.example.com',
      'port'     => 9200,
      'protocol' => 'http',
      'path'     => '/phabricator',
    ));
    $uri = (string)$host->getURI('/_doc/abc');
    $this->assertTrue(strpos($uri, 'es.example.com') !== false);
    $this->assertTrue(strpos($uri, '/_doc/abc') !== false);
  }

}
