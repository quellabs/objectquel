<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\QueryBuilder;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCategoryEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelPostTagEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelTagEntity;
	use Quellabs\ObjectQuel\Tests\Support\SharedTestEntityManager;

	/**
	 * Validates that @Orm\EntityBridge lets QueryBuilder eager-join one hop further
	 * through a linking/junction entity's own relations. RelPostEntity::$postTags is
	 * an ordinary InverseOf collection and RelPostTagEntity::$post/$tag are ordinary
	 * ManyToOne relations — @Orm\EntityBridge only decides whether QueryBuilder
	 * chains eager-loading one hop further; it doesn't collapse or hide the bridge.
	 *
	 * Uses SharedTestEntityManager (SignalHub allows only one EntityManager per
	 * process) and its own tables, created idempotently in setUp() since another
	 * test class may have claimed the shared instance first.
	 */
	class EntityBridgeEagerLoadTest extends TestCase {

		protected function setUp(): void {
			$adapter = SharedTestEntityManager::get()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_posts (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_tags (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_categories (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_post_tags (id INTEGER PRIMARY KEY, post_id INTEGER, tag_id INTEGER, category_id INTEGER)');

			foreach (['rel_post_tags', 'rel_posts', 'rel_tags', 'rel_categories'] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			SharedTestEntityManager::get()->getUnitOfWork()->clear();
		}

		/**
		 * Direct evidence of the query shape: QueryBuilder must emit a second range
		 * for RelTagEntity, chained off the bridge's own alias (not 'main'), purely
		 * because RelPostTagEntity carries @Orm\EntityBridge.
		 */
		public function testQueryBuilderAddsASecondHopThroughAnEntityBridgeMarkedDependent(): void {
			$entityStore = SharedTestEntityManager::get()->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelPostEntity::class, ['id' => 1]);

			// Hop 1: the bridge itself, reached from main via the ordinary InverseOf
			// collection — unchanged, pre-existing behavior.
			$hop1Pattern = '/range of (\w+) is ' . preg_quote(RelPostTagEntity::class, '/') . ' via main\.postTags/';
			self::assertMatchesRegularExpression($hop1Pattern, $query, $query);

			preg_match($hop1Pattern, $query, $matches);
			$bridgeAlias = $matches[1];

			// Hop 2: the far side, chained off the bridge's own alias rather than
			// 'main' — this is the new behavior under test.
			$hop2Pattern = '/range of \w+ is ' . preg_quote(RelTagEntity::class, '/') . ' via ' . preg_quote($bridgeAlias, '/') . '\.tag/';
			self::assertMatchesRegularExpression($hop2Pattern, $query, $query);
		}

		/**
		 * End-to-end: fetching a RelPostEntity must come back with an already-hydrated
		 * RelTagEntity at $post->postTags[0]->tag — not a lazy proxy needing a further
		 * query. This is the behavioral proof the extra hop actually fired.
		 */
		public function testFindEagerlyHydratesTheFarSideOfAnEntityBridgeRelation(): void {
			$em = SharedTestEntityManager::get();

			$post = new RelPostEntity();
			$em->persist($post);
			$em->flush();

			$tag = new RelTagEntity();
			$em->persist($tag);
			$em->flush();

			$postTag = new RelPostTagEntity();
			$postTag->post = $post;
			$postTag->tag = $tag;
			$em->persist($postTag);
			$em->flush();

			$em->getUnitOfWork()->clear();

			/** @var RelPostEntity $loadedPost */
			$loadedPost = $em->find(RelPostEntity::class, $post->getId());

			self::assertNotNull($loadedPost);
			self::assertCount(1, $loadedPost->postTags);

			$loadedTag = $loadedPost->postTags->first()->tag;

			self::assertInstanceOf(RelTagEntity::class, $loadedTag);
			self::assertFalse(
				$loadedTag instanceof ProxyInterface,
				'Expected the far side of the bridge to be eagerly hydrated in the same query, not a lazy proxy.'
			);
			self::assertSame($tag->getId(), $loadedTag->getId());
		}

		/**
		 * Direct evidence of the skip: a LAZY relation on the bridge (RelPostTagEntity::
		 * $category) must not get an extra hop, even though it's an ordinary ManyToOne
		 * that would otherwise qualify like $tag does.
		 */
		public function testLazyBridgeRelationGetsNoExtraHop(): void {
			$entityStore = SharedTestEntityManager::get()->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(RelPostEntity::class, ['id' => 1]);

			self::assertStringNotContainsString(RelCategoryEntity::class, $query, $query);
		}

		/**
		 * End-to-end: the far side of a LAZY bridge relation must still resolve
		 * correctly through the ordinary lazy-proxy path — the skip only affects
		 * eager-join timing, not correctness.
		 */
		public function testLazyBridgeRelationResolvesViaProxy(): void {
			$em = SharedTestEntityManager::get();

			$post = new RelPostEntity();
			$em->persist($post);
			$em->flush();

			$tag = new RelTagEntity();
			$em->persist($tag);
			$em->flush();

			$category = new RelCategoryEntity();
			$em->persist($category);
			$em->flush();

			$postTag = new RelPostTagEntity();
			$postTag->post = $post;
			$postTag->tag = $tag;
			$postTag->category = $category;
			$em->persist($postTag);
			$em->flush();

			$em->getUnitOfWork()->clear();

			/** @var RelPostEntity $loadedPost */
			$loadedPost = $em->find(RelPostEntity::class, $post->getId());
			$loadedCategory = $loadedPost->postTags->first()->category;

			self::assertInstanceOf(RelCategoryEntity::class, $loadedCategory);
			self::assertInstanceOf(
				ProxyInterface::class,
				$loadedCategory,
				'Expected a LAZY bridge relation to come back as a lazy proxy, not eagerly hydrated.'
			);
			self::assertSame($category->getId(), $loadedCategory->getId());
		}

		/**
		 * Control: a dependent entity that is NOT marked @Orm\EntityBridge (the
		 * ordinary RelOrderCascadeEntity/RelCustomerEntity one-to-many pair used
		 * elsewhere in this suite) must be completely unaffected by this feature —
		 * addBridgeExpansionRanges() should no-op for it.
		 */
		public function testNonBridgeDependentsAreUnaffected(): void {
			$entityStore = SharedTestEntityManager::get()->getEntityStore();
			$queryBuilder = new QueryBuilder($entityStore);

			$query = $queryBuilder->prepareQuery(
				\Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCustomerEntity::class,
				['id' => 1]
			);

			self::assertStringContainsString(RelOrderCascadeEntity::class, $query);
			self::assertSame(2, substr_count($query, 'range of'), 'Expected exactly main + one dependent range, no extra hop.');
		}
	}
