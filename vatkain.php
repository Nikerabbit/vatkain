<?hh

require 'vendor/autoload.php';

$gerrit = 'https://gerrit.wikimedia.org/r/';
$projects = [
	'mediawiki/extensions/Translate',
	'mediawiki/extensions/UniversalLanguageSelector',
	'mediawiki/extensions/ContentTranslation',
	'mediawiki/services/cxserver',
	'mediawiki/extensions/Babel',
	'mediawiki/extensions/TwnMainPage',
	'mediawiki/extensions/InviteSignup',
	'mediawiki/extensions/CleanChanges',
	'mediawiki/extensions/LocalisationUpdate',
	'mediawiki/extensions/cldr',
	'mediawiki/extensions/TranslationNotifications',
	'translatewiki',
];

date_default_timezone_set( 'UTC' );

use GuzzleHttp\Client;

class Vatkain {
	private Client $client;

	public function __construct( string $api ) {
		$this->client = new Client( [
			'base_uri' => $api,
		] );
	}

	private function buildQuery( array $input ) {
		$output = '';
		foreach ( $input as $key => $val ) {
			foreach ( (array)$val as $value ) {
				$output .= rawurlencode( $key );
				$output .= '=';
				$output .= rawurlencode( $value );
				$output .= '&';
			}
		}

		return rtrim( $output, '&' );
	}

	public function getChangeData( array<string> $projects ) {
		$step = 100;

		$projects = array_map( $p ==> "project:$p", $projects );
		$projects = '(' . implode( ' OR ', $projects ) . ')';

		$query = [
			'q' => "status:open $projects",
			'S' => 0,
			'n' => $step,
			'o' => [ 'LABELS', 'CURRENT_REVISION' ]
		];

		$data = [];

		while ( true ) {
			$more = false;

			$response = $this->doChangesQuery( $query );
			$json = substr( $response->getBody(), 5 );
			$decoded = json_decode( $json, true );
			foreach ( $decoded as $item ) {
				$data[] = $item;
				if ( isset( $item['_more_changes'] ) ) {
					$query['S'] += $step;
					$more = true;
				}
			}

			if ( !$more ) {
				break;
			}
		}

		return $data;
	}

	private function doChangesQuery( array $query ) {
		return $this->client->get( 'changes/', [ 'query' => $this->buildQuery( $query ) ] );
	}
}

$vatkain = new Vatkain( $gerrit );
$data = $vatkain->getChangeData( $projects );

$now = new DateTime();

foreach ( $data as $index => $item ) {
	// If the latest PS is draft, we do not get to see the revisions, but the patch is not
	// marked as draft itself and thus cannot be filtered out with NOT is:draft.
	if ( $item['revisions'] === [] ) {
		unset( $data[$index] );
		continue;
	}

	$created = DateTime::createFromFormat( 'Y-m-d H:i:s.u???', $item['created'] );
	$data[$index]['__age'] = $created->diff( $now );

	if (
		isset( $item['labels']['Verified']['rejected'] ) ||
		isset( $item['labels']['Code-Review']['rejected'] ) ||
		isset( $item['labels']['Verified']['disliked'] ) ||
		isset( $item['labels']['Code-Review']['disliked'] )
	) {
		$state = 'FIX';
	} else {
		$state = 'REVIEW';
	}

	$data[$index]['__state'] = $state;

	$psCreated = DateTime::createFromFormat( 'Y-m-d H:i:s.u???', $item['revisions'][key($item['revisions'])]['created'] );
	$data[$index]['__ps_age'] = $psCreated->diff( $now );
}

$grouped = [];
foreach ( $data as $item ) {
	$grouped[$item['project']][] = $item;
}

$stringAge = $_ ==> $_['__ps_age']->format( '%a' );

foreach ( $grouped as $ext => $items ) {
	uasort( $grouped[$ext], ( $a, $b ) ==> strnatcmp( $stringAge( $a ), $stringAge( $b ) ) );
}

$fl = ( $string, $length ) ==> str_pad( substr( $string, 0, $length ), $length, ' ' );

$totalReviewIdle = 0;

foreach ( $grouped as $extension => $items ) {
	echo "$extension\n";

	$columnsLengths = [ 8, 40, 6, 8, 8 ];
	$rows = [];
	$rows[] = [ 'Id', 'Subject', 'State', 'Age (d)', 'Idle (d)' ];


	$reviewIdle = 0;
	$unreviewed = 0;
	$patchCount = count( $items );

	foreach ( $items as $item ) {
		$rows[] = [
			$item['change_id'],
			$item['subject'],
			$item['__state'],
			$item['__age']->format( '%a' ),
			$item['__ps_age']->format( '%a' ),
		];

		if ( $item['__state'] === 'REVIEW' && strpos( $item['subject'], 'WIP' ) === false ) {
			$reviewIdle += $item['__ps_age']->format( '%a' );
			$unreviewed += 1;
		}
	}

	foreach ( $rows as $row ) {
		foreach ( $row as $index => &$column ) {
			$column = $fl( $column, $columnsLengths[ $index ] );
		}
		echo implode( '  ', $row ) . "\n";
	}

	$totalReviewIdle += $reviewIdle;
	echo "Combined review-idle-days: $reviewIdle\n";

	$rate = round( $unreviewed / $patchCount * 100 );
	echo "Percentage of patches unreviewed: $rate%\n";

	echo "\n\n";
}

echo "Total combined review-idle-days for all projects: $totalReviewIdle\n";
