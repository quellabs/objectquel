<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * Supplies engine-specific cast syntax from the central TypeMapper.
	 */
	class CastTypeMapper {

		/**
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * CastTypeMapper constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->platform = $platform;
		}

		/**
		 * Returns cast names and their SQL target types for the connected engine.
		 * @return array<string, string> Cast name to SQL target type
		 */
		public function getSupportedCastTypes(): array {
			return TypeMapper::getSupportedCastTypes($this->platform->getDatabaseType());
		}
	}
