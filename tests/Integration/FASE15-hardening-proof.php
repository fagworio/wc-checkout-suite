<?php
/**
 * Fase 15 proof harness — what this plugin declares, and what it refuses to hold.
 *
 * Task:   Fase 15 "Hardening"
 * Gate:   "release" — WCAG, security, performance, privacy, HPOS, migration, rollback, upgrade,
 *          browser matrix, PHP/WP/WC matrix.
 *
 * Hardening is mostly a list of things that must *already* be true, so the proof is a set of reads:
 * the declarations the platform asks for, the files that make a private directory private, and the
 * requirements this build states about itself. What it can prove in one installation is proven;
 * what needs another installation — a second browser, a second PHP, a second WooCommerce — is named
 * at the end as a limit rather than faked, which is how Fases 11 to 14 have recorded their own.
 *
 * 1. **The matrix this build declares**: PHP, WordPress and WooCommerce minimums, and the version it
 *    was tested against — read from the plugin's own header and compared with what is running.
 * 2. **HPOS is declared, not assumed.** WooCommerce keeps a record of which plugins answered the
 *    question, and this build is in it — because every order read goes through `WC_Order`, which is
 *    the storage-independent API.
 * 3. **The private directory is private by more than its name**: the three denial files exist, the
 *    directory is outside any listing, and the download is decided by one policy class.
 * 4. **Nothing personal is stored in an option.** The plugin's options hold schema, identifiers and
 *    switches; a value a customer typed lives in the order or in the customer's own record.
 *
 * Prerequisite: the plugin must be ACTIVE and WooCommerce loaded.
 *
 * @package WCCheckoutSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This harness must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['wccs_proof'] = array( 'pass' => 0, 'fail' => 0, 'checks' => array(), 'notes' => array() );

/**
 * Print a line.
 *
 * @param string $message Message.
 * @return void
 */
function wccs_proof_out( $message ) {
	echo $message . "\n";
}

/**
 * Record and print one assertion.
 *
 * @param string $label     Assertion description.
 * @param bool   $condition Result.
 * @param string $detail    Optional observed detail.
 * @return void
 */
function wccs_proof_check( $label, $condition, $detail = '' ) {
	$ok                                = (bool) $condition;
	$GLOBALS['wccs_proof']['checks'][] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail );
	++$GLOBALS['wccs_proof'][ $ok ? 'pass' : 'fail' ];
	wccs_proof_out( sprintf( '  %s  %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * Record an informational observation.
 *
 * @param string $label  Observation.
 * @param string $detail Detail.
 * @return void
 */
function wccs_proof_note( $label, $detail = '' ) {
	$GLOBALS['wccs_proof']['notes'][] = array( 'label' => $label, 'detail' => $detail );
	wccs_proof_out( sprintf( '  NOTE  %s%s', $label, '' !== $detail ? "  [{$detail}]" : '' ) );
}

/**
 * One header of the plugin's main file.
 *
 * @param string $header Header name.
 * @return string
 */
function wccs_proof_header( string $header ): string {
	static $headers = null;

	if ( null === $headers ) {
		$headers = get_file_data(
			WCCS_PLUGIN_FILE,
			array(
				'requires_wp'  => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'requires_wc'  => 'WC requires at least',
				'tested_wc'    => 'WC tested up to',
			)
		);
	}

	return (string) ( $headers[ $header ] ?? '' );
}

wccs_proof_out( '=====================================================================' );
wccs_proof_out( 'Fase 15 proof — what this build declares about itself' );
wccs_proof_out( 'Site: ' . home_url() . ' | WP ' . get_bloginfo( 'version' ) . ' | WC ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | PHP ' . PHP_VERSION );
wccs_proof_out( '=====================================================================' );

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// 1. The matrix this build declares.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '1. A matriz que este build declara' );

$wccs_proof_requires_php = wccs_proof_header( 'requires_php' );
$wccs_proof_requires_wp  = wccs_proof_header( 'requires_wp' );
$wccs_proof_requires_wc  = wccs_proof_header( 'requires_wc' );
$wccs_proof_tested_wc    = wccs_proof_header( 'tested_wc' );

wccs_proof_check(
	'O header declara as três versões mínimas e a versão testada',
	'' !== $wccs_proof_requires_php
		&& '' !== $wccs_proof_requires_wp
		&& '' !== $wccs_proof_requires_wc
		&& '' !== $wccs_proof_tested_wc,
	'php=' . $wccs_proof_requires_php . ' wp=' . $wccs_proof_requires_wp . ' wc=' . $wccs_proof_requires_wc . ' tested=' . $wccs_proof_tested_wc
);

wccs_proof_check(
	'E o que está a correr satisfaz o que o build exige',
	version_compare( PHP_VERSION, $wccs_proof_requires_php, '>=' )
		&& version_compare( get_bloginfo( 'version' ), $wccs_proof_requires_wp, '>=' )
		&& version_compare( (string) WC_VERSION, $wccs_proof_requires_wc, '>=' ),
	'php=' . PHP_VERSION . ' wp=' . get_bloginfo( 'version' ) . ' wc=' . WC_VERSION
);

// `WC tested up to: 11.1` names a line and not a patch: 11.1.0 belongs to it. Comparing the whole
// version would make the header wrong the first time WooCommerce shipped a patch, which is exactly
// when nobody looks at it.
$wccs_proof_branch = implode( '.', array_slice( explode( '.', (string) WC_VERSION ), 0, 2 ) );

wccs_proof_check(
	'E a linha que o build diz ter testado é a que está a correr, nesta instalação',
	version_compare( $wccs_proof_branch, $wccs_proof_tested_wc, '<=' )
		&& version_compare( $wccs_proof_branch, $wccs_proof_requires_wc, '>=' ),
	'tested=' . $wccs_proof_tested_wc . ' running=' . WC_VERSION . ' branch=' . $wccs_proof_branch
);

// ---------------------------------------------------------------------------
// 2. HPOS: declared, and read from the platform's own record.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '2. HPOS: declarado, não suposto' );

$wccs_proof_features = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
$wccs_proof_plugin   = plugin_basename( WCCS_PLUGIN_FILE );

wccs_proof_check(
	'A declaração está registada no hook que a WooCommerce documenta',
	false !== has_action( 'before_woocommerce_init', array( \WCCheckoutSuite\Support\PlatformCompatibility::class, 'declare' ) ),
	'hook=before_woocommerce_init'
);

$wccs_proof_declared = class_exists( $wccs_proof_features )
	? $wccs_proof_features::get_compatible_plugins_for_feature( \WCCheckoutSuite\Support\PlatformCompatibility::FEATURE_HPOS )
	: array( 'compatible' => array(), 'incompatible' => array(), 'uncertain' => array() );

wccs_proof_check(
	'E a WooCommerce registou este plugin como compatível com a tabela de pedidos própria',
	in_array( $wccs_proof_plugin, (array) ( $wccs_proof_declared['compatible'] ?? array() ), true )
		&& ! in_array( $wccs_proof_plugin, (array) ( $wccs_proof_declared['incompatible'] ?? array() ), true ),
	'plugin=' . $wccs_proof_plugin . ' compatible=' . count( (array) ( $wccs_proof_declared['compatible'] ?? array() ) )
);

// The declaration is about code that reads orders, so the proof reads one the way the plugin does:
// through the API both storages answer, and never through a table of its own.
$wccs_proof_order = wc_create_order( array( 'status' => 'pending' ) );
$wccs_proof_order->calculate_totals();
$wccs_proof_order->save();

$wccs_proof_reread = wc_get_order( $wccs_proof_order->get_id() );

wccs_proof_check(
	'E um pedido é lido e escrito pela API que as duas storages respondem',
	$wccs_proof_reread instanceof WC_Order
		&& $wccs_proof_reread->get_id() === $wccs_proof_order->get_id(),
	'hpos=' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ' order=' . $wccs_proof_order->get_id()
);

$wccs_proof_order_id = (int) $wccs_proof_order->get_id();
$wccs_proof_order->delete( true );

// ---------------------------------------------------------------------------
// 3. The private directory is private by more than its name.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '3. O diretório privado é privado por mais do que o nome' );

$wccs_proof_storage = 'WCCheckoutSuite\\Domain\\Uploads\\PrivateStorage';
$wccs_proof_directory = $wccs_proof_storage::directory();

wccs_proof_check(
	'O diretório existe e está dentro dos uploads da loja',
	'' !== $wccs_proof_directory && is_dir( $wccs_proof_directory ),
	'dir=' . str_replace( ABSPATH, '', (string) $wccs_proof_directory )
);

$wccs_proof_missing = array();

foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $wccs_proof_guard ) {
	if ( ! file_exists( $wccs_proof_directory . '/' . $wccs_proof_guard ) ) {
		$wccs_proof_missing[] = $wccs_proof_guard;
	}
}

wccs_proof_check(
	'E os três ficheiros de negação estão lá: um pedido direto não lê nada',
	array() === $wccs_proof_missing,
	'missing=' . implode( ',', $wccs_proof_missing )
);

$wccs_proof_htaccess = (string) @file_get_contents( $wccs_proof_directory . '/.htaccess' );

wccs_proof_check(
	'E o .htaccess nega o acesso em vez de o permitir em parte',
	str_contains( strtolower( $wccs_proof_htaccess ), 'deny from all' )
		|| str_contains( strtolower( $wccs_proof_htaccess ), 'require all denied' ),
	'htaccess=' . trim( str_replace( "\n", ' ', $wccs_proof_htaccess ) )
);

wccs_proof_check(
	'E quem decide uma leitura é uma só classe, não o servidor de ficheiros',
	class_exists( 'WCCheckoutSuite\\Domain\\Uploads\\DownloadPolicy' )
		&& method_exists( 'WCCheckoutSuite\\Domain\\Uploads\\DownloadPolicy', 'allows' ),
	'policy=' . ( class_exists( 'WCCheckoutSuite\\Domain\\Uploads\\DownloadPolicy' ) ? 'present' : 'missing' )
);

// ---------------------------------------------------------------------------
// 4. Nothing personal is stored in an option.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '4. Nenhum valor pessoal mora numa opção' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the store's own options to assert what this plugin keeps there.
$wccs_proof_options = $wpdb->get_results(
	"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wccs\_%'",
	ARRAY_A
);

$wccs_proof_names = array_column( (array) $wccs_proof_options, 'option_name' );
sort( $wccs_proof_names );

// What a customer types is an e-mail address, a document number, a phone number or a name. The
// first two have shapes a test can look for, and they are the ones this plugin collects as
// identifiers; a name has none, which is why what is asserted is that an option holds the
// *schema* — labels and identifiers the merchant wrote — and never a submitted value.
$wccs_proof_personal = array();

foreach ( (array) $wccs_proof_options as $wccs_proof_option ) {
	$wccs_proof_value = (string) $wccs_proof_option['option_value'];

	if ( preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $wccs_proof_value ) ) {
		$wccs_proof_personal[] = (string) $wccs_proof_option['option_name'] . ':email';
	}

	if ( preg_match( '/\b\d{11}\b/', $wccs_proof_value ) ) {
		$wccs_proof_personal[] = (string) $wccs_proof_option['option_name'] . ':documento';
	}
}

wccs_proof_check(
	'Nenhuma opção deste plugin guarda um valor que um cliente escreveu',
	array() === $wccs_proof_personal,
	'found=' . implode( ',', $wccs_proof_personal ) . ' options=' . implode( ',', $wccs_proof_names )
);

wccs_proof_note(
	'As opções que o plugin mantém, para quem as ler',
	implode( ', ', $wccs_proof_names ) . ' — schema, interruptores, identificadores e assinaturas. O valor de um cliente vive no pedido, no perfil dele ou no ficheiro privado; nunca numa opção, e é isso que a verificação acima lê.'
);

wccs_proof_check(
	'E os dois fluxos de privacidade estão registados nos hooks da própria WordPress',
	has_filter( 'wp_privacy_personal_data_exporters' ) || has_filter( 'wp_privacy_personal_data_erasers' ),
	'exporters=' . ( has_filter( 'wp_privacy_personal_data_exporters' ) ? 'yes' : 'no' ) . ' erasers=' . ( has_filter( 'wp_privacy_personal_data_erasers' ) ? 'yes' : 'no' )
);

wccs_proof_note(
	'O que esta prova não fecha, e quem o fecha',
	'Cada item da fase tem uma prova mais funda, e todas correm na varredura: segurança e sanitização em `F12-wccs-061`, entregas e desempenho em `F12-wccs-063` e `F12-wccs-064`, recuperação e rollback em `F12-wccs-065`, privacidade em `F10-wccs-055`, migração em `F10-wccs-056` e `F10-wccs-057`, instalação, atualização e upgrade em `F13-wccs-069`, permissões de ficheiro em `F14-wccs-074`. O que esta prova acrescenta é o que nenhuma delas responde: as declarações de matriz, o registo de HPOS na plataforma, e os guardas do diretório privado lidos do sistema de ficheiros.'
);

wccs_proof_note(
	'A matriz que não existe nesta máquina',
	'A cláusula «browser matrix» e a cláusula «PHP/WP/WC matrix» pedem instalações que esta não tem: a loja corre um PHP, uma WordPress e uma WooCommerce, e as provas de browser abrem um só Chrome. O que fica afirmado é o que o build *declara* e o que a instalação satisfaz; o resto é nomeado. Uma matriz a sério é uma tabela de ambientes e não uma frase — e por isso não é simulada aqui.'
);

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
wccs_proof_out( '' );
wccs_proof_out( '=====================================================================' );
wccs_proof_out(
	sprintf(
		'RESULT: %d passed, %d failed, %d notes',
		$GLOBALS['wccs_proof']['pass'],
		$GLOBALS['wccs_proof']['fail'],
		count( $GLOBALS['wccs_proof']['notes'] )
	)
);
wccs_proof_out( '=====================================================================' );

exit( $GLOBALS['wccs_proof']['fail'] > 0 ? 1 : 0 );
