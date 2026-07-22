<?php
/**
 * Enumerates AI models available for AI editing.
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

/** Runtime discovery of usable AI editing models. */
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
	 * SDK-SEAM: enumerate models suitable for the complete AI editing workflow.
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
		$requirements = self::ai_edit_requirements();
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
				$label    = is_object( $metadata ) && method_exists( $metadata, 'getName' ) ? (string) $metadata->getName() : '';
				$models[] = array(
					'id'    => $id,
					'label' => '' !== $label ? $label : $id,
				);
			}
		}
		return $models;
	}

	/**
	 * SDK-SEAM: build requirements for the complete AI editing workflow.
	 *
	 * AI editing needs more than text generation: it sends a system instruction,
	 * performs function calls over multiple chat-history turns, and requests a
	 * JSON-schema final summary. Keep this list aligned with Ai_Agent and
	 * Ai_Client_WP so a selectable model can complete every part of that flow.
	 *
	 * @return object|null ModelRequirements instance, or null when unavailable.
	 */
	private static function ai_edit_requirements() {
		$requirements_class    = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements';
		$required_option_class = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\RequiredOption';
		$capability_class      = '\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum';
		$option_class          = '\\WordPress\\AiClient\\Providers\\Models\\Enums\\OptionEnum';
		$modality_class        = '\\WordPress\\AiClient\\Messages\\Enums\\ModalityEnum';
		if (
			! class_exists( $requirements_class ) ||
			! class_exists( $required_option_class ) ||
			! class_exists( $capability_class ) ||
			! class_exists( $option_class ) ||
			! class_exists( $modality_class ) ||
			! method_exists( $capability_class, 'from' ) ||
			! method_exists( $option_class, 'from' ) ||
			! method_exists( $modality_class, 'from' ) ||
			! defined( $capability_class . '::TEXT_GENERATION' ) ||
			! defined( $capability_class . '::CHAT_HISTORY' ) ||
			! defined( $modality_class . '::TEXT' )
		) {
			return null;
		}

		return new $requirements_class(
			array(
				$capability_class::from( $capability_class::TEXT_GENERATION ),
				$capability_class::from( $capability_class::CHAT_HISTORY ),
			),
			array(
				new $required_option_class(
					$option_class::from( 'inputModalities' ),
					array( $modality_class::from( $modality_class::TEXT ) )
				),
				new $required_option_class(
					$option_class::from( 'systemInstruction' ),
					Ai_Prompt::system_prompt()
				),
				new $required_option_class(
					$option_class::from( 'functionDeclarations' ),
					true
				),
				new $required_option_class(
					$option_class::from( 'outputMimeType' ),
					'application/json'
				),
				new $required_option_class(
					$option_class::from( 'outputSchema' ),
					Ai_Agent::FINAL_SUMMARY_JSON_SCHEMA
				),
			)
		);
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
