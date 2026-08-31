<?php
declare(strict_types=1);

$mock_manager = <<<'PHP'
<?php
namespace Elementor\Core\DynamicTags;

class Manager {
	public function create_tag( $tag_id, $tag_name, array $settings = [] ) {
		$tag_class = $this->get_tag_data_class( $tag_id, $tag_name, $settings );
		if ( ! $tag_class ) {
			return null;
		}
		return new $tag_class( $settings );
	}

	public function get_tag_data_content( $tag_id, $tag_name, array $settings = [] ) {
		return $this->create_tag( $tag_id, $tag_name, $settings );
	}

	public function get_tag_data_class( $tag_id, $tag_name, array $settings = [] ) {
		return $this->get_tag_class( $tag_name );
	}
}
PHP;

function patch_manager_code( string $content ): string {
	// 1. Remove strict array typehint from create_tag, get_tag_data_content, get_tag_data_class, get_tag_data_classes
	$pattern = '/function\s+([a-zA-Z0-9_]+)\s*\(\s*(\$tag_id\s*,\s*\$tag_name\s*,)\s*array\s*(\$settings\b)/i';
	$content = preg_replace( $pattern, 'function $1( $2 $3', $content );

	// 2. Add fallback $settings = is_array( $settings ) ? $settings : []; inside create_tag
	if ( false === strpos( $content, '$settings = is_array( $settings ) ? $settings : [];' ) ) {
		$content = preg_replace(
			'/(public\s+function\s+create_tag\s*\([^)]*\)\s*\{)/i',
			"$1\n\t\t" . '$settings = is_array( $settings ) ? $settings : [];',
			$content,
			1
		);
		$content = preg_replace(
			'/(public\s+function\s+get_tag_data_content\s*\([^)]*\)\s*\{)/i',
			"$1\n\t\t" . '$settings = is_array( $settings ) ? $settings : [];',
			$content,
			1
		);
	}

	return $content;
}

$patched = patch_manager_code( $mock_manager );
echo $patched . "\n";

assert( false === strpos( $patched, 'create_tag( $tag_id, $tag_name, array $settings' ), 'create_tag nao deve ter typehint array' );
assert( false === strpos( $patched, 'get_tag_data_content( $tag_id, $tag_name, array $settings' ), 'get_tag_data_content nao deve ter typehint array' );
assert( false === strpos( $patched, 'get_tag_data_class( $tag_id, $tag_name, array $settings' ), 'get_tag_data_class nao deve ter typehint array' );
assert( false !== strpos( $patched, '$settings = is_array( $settings ) ? $settings : [];' ), 'deve ter protecao contra null em $settings' );

echo "Patch logic validated 100% successfully!\n";
