<?php

namespace MediaWiki\Tests\Storage;

use BagOStuff;
use EmptyBagOStuff;
use HashBagOStuff;
use MediaWiki\Storage\NameTableAccessException;
use MediaWiki\Storage\NameTableStore;
use MediaWikiTestCase;
use Psr\Log\NullLogger;
use WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\LoadBalancer;
use Wikimedia\TestingAccessWrapper;

/**
 * @author Addshore
 * @group Database
 * @covers \MediaWiki\Storage\NameTableStore
 */
class NameTableStoreTest extends MediaWikiTestCase {

	public function setUp() {
		$this->tablesUsed[] = 'slot_roles';
		parent::setUp();
	}

	protected function addCoreDBData() {
		// The default implementation causes the slot_roles to already have content. Skip that.
	}

	private function populateTable( $values ) {
		$insertValues = [];
		foreach ( $values as $name ) {
			$insertValues[] = [ 'role_name' => $name ];
		}
		$this->db->insert( 'slot_roles', $insertValues );
	}

	private function getHashWANObjectCache( $cacheBag ) {
		return new WANObjectCache( [ 'cache' => $cacheBag ] );
	}

	/**
	 * @param $db
	 * @return \PHPUnit_Framework_MockObject_MockObject|LoadBalancer
	 */
	private function getMockLoadBalancer( $db ) {
		$mock = $this->getMockBuilder( LoadBalancer::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->expects( $this->any() )
			->method( 'getConnection' )
			->willReturn( $db );
		return $mock;
	}

	private function getCallCheckingDb( $insertCalls, $selectCalls ) {
		$mock = $this->getMockBuilder( Database::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->expects( $this->exactly( $insertCalls ) )
			->method( 'insert' )
			->willReturnCallback( function ( ...$args ) {
				return call_user_func_array( [ $this->db, 'insert' ], $args );
			} );
		$mock->expects( $this->exactly( $selectCalls ) )
			->method( 'select' )
			->willReturnCallback( function ( ...$args ) {
				return call_user_func_array( [ $this->db, 'select' ], $args );
			} );
		$mock->expects( $this->exactly( $insertCalls ) )
			->method( 'affectedRows' )
			->willReturnCallback( function ( ...$args ) {
				return call_user_func_array( [ $this->db, 'affectedRows' ], $args );
			} );
		$mock->expects( $this->any() )
			->method( 'insertId' )
			->willReturnCallback( function ( ...$args ) {
				return call_user_func_array( [ $this->db, 'insertId' ], $args );
			} );
		return $mock;
	}

	private function getNameTableSqlStore(
		BagOStuff $cacheBag,
		$insertCalls,
		$selectCalls,
		$normalizationCallback = null,
		$insertCallback = null
	) {
		return new NameTableStore(
			$this->getMockLoadBalancer( $this->getCallCheckingDb( $insertCalls, $selectCalls ) ),
			$this->getHashWANObjectCache( $cacheBag ),
			new NullLogger(),
			'slot_roles', 'role_id', 'role_name',
			$normalizationCallback,
			false,
			$insertCallback
		);
	}

	public function provideGetAndAcquireId() {
		return [
			'no wancache, empty table' =>
				[ new EmptyBagOStuff(), true, 1, [], 'foo', 1 ],
			'no wancache, one matching value' =>
				[ new EmptyBagOStuff(), false, 1, [ 'foo' ], 'foo', 1 ],
			'no wancache, one not matching value' =>
				[ new EmptyBagOStuff(), true, 1, [ 'bar' ], 'foo', 2 ],
			'no wancache, multiple, one matching value' =>
				[ new EmptyBagOStuff(), false, 1, [ 'foo', 'bar' ], 'bar', 2 ],
			'no wancache, multiple, no matching value' =>
				[ new EmptyBagOStuff(), true, 1, [ 'foo', 'bar' ], 'baz', 3 ],
			'wancache, empty table' =>
				[ new HashBagOStuff(), true, 1, [], 'foo', 1 ],
			'wancache, one matching value' =>
				[ new HashBagOStuff(), false, 1, [ 'foo' ], 'foo', 1 ],
			'wancache, one not matching value' =>
				[ new HashBagOStuff(), true, 1, [ 'bar' ], 'foo', 2 ],
			'wancache, multiple, one matching value' =>
				[ new HashBagOStuff(), false, 1, [ 'foo', 'bar' ], 'bar', 2 ],
			'wancache, multiple, no matching value' =>
				[ new HashBagOStuff(), true, 1, [ 'foo', 'bar' ], 'baz', 3 ],
		];
	}

	/**
	 * @dataProvider provideGetAndAcquireId
	 * @param BagOStuff $cacheBag to use in the WANObjectCache service
	 * @param bool $needsInsert Does the value we are testing need to be inserted?
	 * @param int $selectCalls Number of times the select DB method will be called
	 * @param string[] $existingValues to be added to the db table
	 * @param string $name name to acquire
	 * @param int $expectedId the id we expect the name to have
	 */
	public function testGetAndAcquireId(
		$cacheBag,
		$needsInsert,
		$selectCalls,
		$existingValues,
		$name,
		$expectedId
	) {
		// Make sure the table is empty!
		$this->truncateTable( 'slot_roles' );

		$this->populateTable( $existingValues );
		$store = $this->getNameTableSqlStore( $cacheBag, (int)$needsInsert, $selectCalls );

		// Some names will not initially exist
		try {
			$result = $store->getId( $name );
			$this->assertSame( $expectedId, $result );
		} catch ( NameTableAccessException $e ) {
			if ( $needsInsert ) {
				$this->assertTrue( true ); // Expected exception
			} else {
				$this->fail( 'Did not expect an exception, but got one: ' . $e->getMessage() );
			}
		}

		// All names should return their id here
		$this->assertSame( $expectedId, $store->acquireId( $name ) );

		// acquireId inserted these names, so now everything should exist with getId
		$this->assertSame( $expectedId, $store->getId( $name ) );

		// calling getId again will also still work, and not result in more selects
		$this->assertSame( $expectedId, $store->getId( $name ) );
	}

	public function provideTestGetAndAcquireIdNameNormalization() {
		yield [ 'A', 'a', 'strtolower' ];
		yield [ 'b', 'B', 'strtoupper' ];
		yield [
			'X',
			'X',
			function ( $name ) {
				return $name;
			}
		];
		yield [ 'ZZ', 'ZZ-a', __CLASS__ . '::appendDashAToString' ];
	}

	public static function appendDashAToString( $string ) {
		return $string . '-a';
	}

	/**
	 * @dataProvider provideTestGetAndAcquireIdNameNormalization
	 */
	public function testGetAndAcquireIdNameNormalization(
		$nameIn,
		$nameOut,
		$normalizationCallback
	) {
		$store = $this->getNameTableSqlStore(
			new EmptyBagOStuff(),
			1,
			1,
			$normalizationCallback
		);
		$acquiredId = $store->acquireId( $nameIn );
		$this->assertSame( $nameOut, $store->getName( $acquiredId ) );
	}

	public function provideGetName() {
		return [
			[ new HashBagOStuff(), 3, 2 ],
			[ new EmptyBagOStuff(), 3, 3 ],
		];
	}

	/**
	 * @dataProvider provideGetName
	 */
	public function testGetName( BagOStuff $cacheBag, $insertCalls, $selectCalls ) {
		$now = microtime( true );
		$cacheBag->setMockTime( $now );
		// Check for operations to in-memory cache (IMC) and persistent cache (PC)
		$store = $this->getNameTableSqlStore( $cacheBag, $insertCalls, $selectCalls );

		// Get 1 ID and make sure getName returns correctly
		$fooId = $store->acquireId( 'foo' ); // regen PC, set IMC, update IMC, tombstone PC
		$now += 0.01;
		$this->assertSame( 'foo', $store->getName( $fooId ) ); // use IMC
		$now += 0.01;

		// Get another ID and make sure getName returns correctly
		$barId = $store->acquireId( 'bar' ); // update IMC, tombstone PC
		$now += 0.01;
		$this->assertSame( 'bar', $store->getName( $barId ) ); // use IMC
		$now += 0.01;

		// Blitz the cache and make sure it still returns
		TestingAccessWrapper::newFromObject( $store )->tableCache = null; // clear IMC
		$this->assertSame( 'foo', $store->getName( $fooId ) ); // regen interim PC, set IMC
		$this->assertSame( 'bar', $store->getName( $barId ) ); // use IMC

		// Blitz the cache again and get another ID and make sure getName returns correctly
		TestingAccessWrapper::newFromObject( $store )->tableCache = null; // clear IMC
		$bazId = $store->acquireId( 'baz' ); // set IMC using interim PC, update IMC, tombstone PC
		$now += 0.01;
		$this->assertSame( 'baz', $store->getName( $bazId ) ); // uses IMC
		$this->assertSame( 'baz', $store->getName( $bazId ) ); // uses IMC
	}

	public function testGetName_masterFallback() {
		$store = $this->getNameTableSqlStore( new EmptyBagOStuff(), 1, 2 );

		// Insert a new name
		$fooId = $store->acquireId( 'foo' );

		// Empty the process cache, getCachedTable() will now return this empty array
		TestingAccessWrapper::newFromObject( $store )->tableCache = [];

		// getName should fallback to master, which is why we assert 2 selectCalls above
		$this->assertSame( 'foo', $store->getName( $fooId ) );
	}

	public function testGetMap_empty() {
		$this->populateTable( [] );
		$store = $this->getNameTableSqlStore( new HashBagOStuff(), 0, 1 );
		$table = $store->getMap();
		$this->assertSame( [], $table );
	}

	public function testGetMap_twoValues() {
		$this->populateTable( [ 'foo', 'bar' ] );
		$store = $this->getNameTableSqlStore( new HashBagOStuff(), 0, 1 );

		// We are using a cache, so 2 calls should only result in 1 select on the db
		$store->getMap();
		$table = $store->getMap();

		$expected = [ 1 => 'foo', 2 => 'bar' ];
		$this->assertSame( $expected, $table );
		// Make sure the table returned is the same as the cached table
		$this->assertSame( $expected, TestingAccessWrapper::newFromObject( $store )->tableCache );
	}

	public function testReloadMap() {
		$this->populateTable( [ 'foo' ] );
		$store = $this->getNameTableSqlStore( new HashBagOStuff(), 0, 2 );

		// force load
		$this->assertCount( 1, $store->getMap() );

		// add more stuff to the table, so the cache gets out of sync
		$this->populateTable( [ 'bar' ] );

		$expected = [ 1 => 'foo', 2 => 'bar' ];
		$this->assertSame( $expected, $store->reloadMap() );
		$this->assertSame( $expected, $store->getMap() );
	}

	public function testCacheRaceCondition() {
		$wanHashBag = new HashBagOStuff();
		$store1 = $this->getNameTableSqlStore( $wanHashBag, 1, 1 );
		$store2 = $this->getNameTableSqlStore( $wanHashBag, 1, 0 );
		$store3 = $this->getNameTableSqlStore( $wanHashBag, 1, 1 );

		// Cache the current table in the instances we will use
		// This simulates multiple requests running simultaneously
		$store1->getMap();
		$store2->getMap();
		$store3->getMap();

		// Store 2 separate names using different instances
		$fooId = $store1->acquireId( 'foo' );
		$barId = $store2->acquireId( 'bar' );

		// Each of these instances should be aware of what they have inserted
		$this->assertSame( $fooId, $store1->acquireId( 'foo' ) );
		$this->assertSame( $barId, $store2->acquireId( 'bar' ) );

		// A new store should be able to get both of these new Ids
		// Note: before there was a race condition here where acquireId( 'bar' ) would update the
		//       cache with data missing the 'foo' key that it was not aware of
		$store4 = $this->getNameTableSqlStore( $wanHashBag, 0, 1 );
		$this->assertSame( $fooId, $store4->getId( 'foo' ) );
		$this->assertSame( $barId, $store4->getId( 'bar' ) );

		// If a store with old cached data tries to acquire these we will get the same ids.
		$this->assertSame( $fooId, $store3->acquireId( 'foo' ) );
		$this->assertSame( $barId, $store3->acquireId( 'bar' ) );
	}

	public function testGetAndAcquireIdInsertCallback() {
		// FIXME: fails under postgres
		$this->markTestSkippedIfDbType( 'postgres' );

		$store = $this->getNameTableSqlStore(
			new EmptyBagOStuff(),
			1,
			1,
			null,
			function ( $insertFields ) {
				$insertFields['role_id'] = 7251;
				return $insertFields;
			}
		);
		$this->assertSame( 7251, $store->acquireId( 'A' ) );
	}

}
