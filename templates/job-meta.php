<?php
/**
 * Job facts list.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/job-meta.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var int      $post_id Job post ID.
 * @var string[] $fields  Field keys to render, in order.
 */

defined( 'ABSPATH' ) || exit;

// As with the card, a chosen or custom layout is a token template.
$jszr_info_template = \JobsSyncForZohoRecruit\Layouts::job_info_template();

if ( '' !== $jszr_info_template ) {
	echo wp_kses(
		\JobsSyncForZohoRecruit\Template_Tags::render( $jszr_info_template, $post_id ),
		\JobsSyncForZohoRecruit\Layouts::allowed_html()
	);

	return;
}

// Derived, so a taxonomy registered through jszr_taxonomies is displayable too.
$jszr_taxonomies = \JobsSyncForZohoRecruit\REST_API::filter_map();

$jszr_meta_fields = array(
	'job_code'     => array( '_jszr_job_code', __( 'Job code', 'jobs-sync-for-zoho-recruit' ), 'text' ),
	'salary'       => array( '_zoho_recruit_salary', __( 'Salary', 'jobs-sync-for-zoho-recruit' ), 'text' ),
	'industry'     => array( '_zoho_recruit_industry', __( 'Industry', 'jobs-sync-for-zoho-recruit' ), 'text' ),
	'client'       => array( '_zoho_recruit_client', __( 'Client', 'jobs-sync-for-zoho-recruit' ), 'text' ),
	'remote'       => array( '_zoho_recruit_remote', __( 'Work mode', 'jobs-sync-for-zoho-recruit' ), 'text' ),
	'positions'    => array( '_zoho_recruit_positions', __( 'Openings', 'jobs-sync-for-zoho-recruit' ), 'number' ),
	'posted_date'  => array( '_zoho_recruit_posted_date', __( 'Posted', 'jobs-sync-for-zoho-recruit' ), 'date' ),
	'closing_date' => array( '_zoho_recruit_closing_date', __( 'Closes', 'jobs-sync-for-zoho-recruit' ), 'date' ),
);

$jszr_labels = \JobsSyncForZohoRecruit\Settings::available_job_info_fields();

$jszr_rows = array();

foreach ( (array) $fields as $jszr_field ) {
	$jszr_field = (string) $jszr_field;

	if ( isset( $jszr_taxonomies[ $jszr_field ] ) ) {
		$jszr_terms = get_the_terms( $post_id, $jszr_taxonomies[ $jszr_field ] );

		if ( is_array( $jszr_terms ) && ! empty( $jszr_terms ) ) {
			$jszr_rows[] = array(
				'label' => isset( $jszr_labels[ $jszr_field ] ) ? $jszr_labels[ $jszr_field ] : $jszr_field,
				'value' => implode( ', ', wp_list_pluck( $jszr_terms, 'name' ) ),
			);
		}

		continue;
	}

	if ( ! isset( $jszr_meta_fields[ $jszr_field ] ) ) {
		continue;
	}

	list( $jszr_key, $jszr_label, $jszr_type ) = $jszr_meta_fields[ $jszr_field ];

	$jszr_value = (string) jszr_get_job_meta( $post_id, $jszr_key );

	if ( '' === $jszr_value ) {
		continue;
	}

	if ( 'date' === $jszr_type ) {
		$jszr_stamp = strtotime( $jszr_value );

		if ( $jszr_stamp ) {
			$jszr_value = wp_date( (string) get_option( 'date_format' ), $jszr_stamp );
		}
	} elseif ( 'number' === $jszr_type ) {
		$jszr_value = number_format_i18n( (float) $jszr_value );
	}

	$jszr_rows[] = array(
		'label' => $jszr_label,
		'value' => $jszr_value,
	);
}

if ( empty( $jszr_rows ) ) {
	return;
}
?>
<dl class="jszr-job-meta">
	<?php foreach ( $jszr_rows as $jszr_row ) : ?>
		<div class="jszr-job-meta__item">
			<dt class="jszr-job-meta__label"><?php echo esc_html( $jszr_row['label'] ); ?></dt>
			<dd class="jszr-job-meta__value"><?php echo esc_html( $jszr_row['value'] ); ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
