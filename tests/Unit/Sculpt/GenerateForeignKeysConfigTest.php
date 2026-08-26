<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;

	/**
	 * Part 2.2 — the generate_foreign_keys config gate: absent-from-config and
	 * explicit false must both resolve to Configuration::getGenerateForeignKeys()
	 * === false (an existing config/database.php from before this feature shipped
	 * needs zero changes to keep behaving exactly as it does today); explicit
	 * true must round-trip; and getConfigValueAsBool() must not coerce a string
	 * value, matching the strict is_string()/is_int() behavior
	 * getConfigValueAsString()/getConfigValueAsInt() already apply.
	 */
	class GenerateForeignKeysConfigTest extends TestCase {

		private function makeProvider(array $config): ServiceProvider {
			$provider = new ServiceProvider();
			$provider->setConfig($config);
			return $provider;
		}

		public function testAbsentFromConfigDefaultsToFalse(): void {
			$configuration = $this->makeProvider([])->getConfiguration();

			self::assertFalse($configuration->getGenerateForeignKeys());
		}

		public function testExplicitFalseIsHonored(): void {
			$configuration = $this->makeProvider(['generate_foreign_keys' => false])->getConfiguration();

			self::assertFalse($configuration->getGenerateForeignKeys());
		}

		public function testExplicitTrueRoundTrips(): void {
			$configuration = $this->makeProvider(['generate_foreign_keys' => true])->getConfiguration();

			self::assertTrue($configuration->getGenerateForeignKeys());
		}

		/**
		 * getConfigValueAsBool() applies the same strict-type gate as its string/int
		 * siblings — a non-bool value (even a string that reads as truthy) falls
		 * back to the default rather than being coerced.
		 */
		public function testNonBoolConfigValueFallsBackToDefaultRatherThanBeingCoerced(): void {
			$configuration = $this->makeProvider(['generate_foreign_keys' => 'true'])->getConfiguration();

			self::assertFalse($configuration->getGenerateForeignKeys());
		}

		public function testConfigurationDefaultsToFalseWithoutAnyServiceProviderInvolved(): void {
			$configuration = new \Quellabs\ObjectQuel\Configuration();

			self::assertFalse($configuration->getGenerateForeignKeys());
		}

		public function testConfigurationSetterRoundTrips(): void {
			$configuration = new \Quellabs\ObjectQuel\Configuration();
			$configuration->setGenerateForeignKeys(true);

			self::assertTrue($configuration->getGenerateForeignKeys());
		}
	}
