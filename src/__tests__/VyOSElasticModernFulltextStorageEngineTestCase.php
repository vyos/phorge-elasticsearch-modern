<?php

final class VyOSElasticModernFulltextStorageEngineTestCase
  extends PhutilTestCase {

  public function testSetVersionAcceptsSevenAndAbove() {
    foreach (array(7, 8, 9, 100) as $v) {
      $engine = new VyOSElasticModernFulltextStorageEngine();
      $caught = null;
      try {
        $engine->setVersion($v);
      } catch (Exception $e) {
        $caught = $e;
      }
      $this->assertEqual(
        null,
        $caught,
        pht('Expected no exception for version=%d.', $v));
      $this->assertEqual($v, $engine->getVersion());
    }
  }

  public function testSetVersionRejectsBelowSeven() {
    foreach (array(0, 1, 2, 5, 6) as $v) {
      $engine = new VyOSElasticModernFulltextStorageEngine();
      $caught = null;
      try {
        $engine->setVersion($v);
      } catch (Exception $e) {
        $caught = $e;
      }
      $this->assertTrue(
        $caught !== null,
        pht('Expected an exception for version=%d.', $v));
    }
  }

}
