<?php
/**
 * Enumerates AI models available for text-generation editing.
 *
 * The model catalog is owned by the WordPress AI Client SDK and its configured
 * providers (Connectors), not by this plugin. This helper reads that catalog at
 * runtime so the settings dropdown follows new models without a plugin update.
 *
 * The SDK is provided by WordPress core at runtime and is not vendored here, so
 * every SDK call is defensively guarded: on any failure the list is empty and
 * the caller falls back to "auto" (let the AI Client pick).
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runtime discovery of usable text-generation models. */
class Ai_Models {

	/**
	 * List models that can serve AI editing, each as array{id:string,label:string}.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public static function available_for_text(): array {
		$models = array();
		if ( Ai_Availability::is_sdk_present() ) {
			try {
				$models = self::enumerate_text_models();
			} catch ( \Throwable $error ) {
				$models = array();
			}
		}

		/**
		 * Filter the AI models offered in settings.
		 *
		 * Lets sites inject a curated list when SDK enumeration is unavailable.
		 *
		 * @param array<int,array{id:string,label:string}> $models Discovered models.
		 */
		$models = apply_filters( 'kayzart_ai_available_models', $models );

		return self::normalize_list( is_array( $models ) ? $models : array() );
	}

	/**
	 * SDK-SEAM: enumerate text-generation models from the default registry.
	 *
	 * Verify against the SDK: AiClient::defaultRegistry(),
	 * ProviderRegistry::findModelsMetadataForSupport(ModelRequirements),
	 * ProviderModelsMetadata::getModels(), ModelMetadata::getId()/getName().
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function enumerate_text_models(): array {
		$client = '\\WordPress\\AiClient\\AiClient';
		if ( ! class_exists( $client ) || ! method_exists( $client, 'defaultRegistry' ) ) {
			return array();
		}
		$registry = $client::defaultRegistry();
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'findModelsMetadataForSupport' ) ) {
			return array();
		}
		$requirements = self::text_requirements();
		if ( null === $requirements ) {
			return array();
		}

		$groups = $registry->findModelsMetadataForSupport( $requirements );
		if ( ! is_array( $groups ) ) {
			return array();
		}

		$models = array();
		foreach ( $groups as $group ) {
			foreach ( self::group_models( $group ) as $metadata ) {
				$id = is_object( $metadata ) && method_exists( $metadata, 'getId' ) ? (string) $metadata->getId() : '';
				if ( '' === $id ) {
					continue;
				}
				$label      = is_object( $metadata ) && method_exists( $metadata, 'getName' ) ? (string) $metadata->getName() : '';
				$models[] = array(
					'id'    => $id,
					'label' => '' !== $label ? $label : $id,
				);
			}
		}
		return $models;
	}

	/**
	 * SDK-SEAM: build the text-generation requirements object.
	 *
	 * @return object|null ModelRequirements instance, or null when unavailable.
	 */
	private static function text_requirements() {
		$requirements_class = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements';
		$capability_class   = '\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum';
		if ( ! class_exists( $requirements_class ) || ! class_exists( $capability_class ) || ! method_exists( $capability_class, 'textGeneration' ) ) {
			return null;
		}
		return new $requirements_class( array( $capability_class::textGeneration() ), array() );
	}

	/**
	 * SDK-SEAM: read the model list from a ProviderModelsMetadata group.
	 *
	 * @param mixed $group ProviderModelsMetadata instance.
	 * @return array<int,object>
	 */
	private static function group_models( $group ): array {
		if ( is_object( $group ) && method_exists( $group, 'getModels' ) ) {
			$models = $group->getModels();
			return is_array( $models ) ? $models : array();
		}
		return array();
	}

	/**
	 * Sanitize and de-duplicate a model list by ID, preserving order.
	 *
	 * @param array $models Raw model list.
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function normalize_list( array $models ): array {
		$seen   = array();
		$result = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}
			$id = trim( (string) $model['id'] );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$label       = isset( $model['label'] ) ? trim( (string) $model['label'] ) : '';
			$result[]    = array(
				'id'    => $id,
				'label' => '' !== $label ? $label : $id,
			);
		}
		return $result;
	}
}
