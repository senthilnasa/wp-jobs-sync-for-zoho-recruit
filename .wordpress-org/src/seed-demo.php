<?php
/**
 * Seed a development site with demo jobs for the listing screenshots.
 *
 * The records go through the plugin's real sync path — the mapper, the writer,
 * the taxonomies — so the screenshots show what the plugin actually produces
 * rather than hand-built posts.
 *
 * Development sites only. It writes real posts.
 *
 *     wp eval-file .wordpress-org/src/seed-demo.php
 *     wp eval-file .wordpress-org/src/seed-demo.php cleanup
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;

use function JobsSyncForZohoRecruit\plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Build one demo Zoho record.
 *
 * @param array $fields Overrides merged over the defaults.
 * @return array
 */
function jszr_demo_record( array $fields ) {
	return array_merge(
		array(
			'Job_Opening_Status'        => 'In-progress',
			'Country'                   => 'India',
			'Remote_Job'                => false,
			'Number_of_Positions'       => '1',
			'Date_Opened'               => '2026-08-12',
			'Expected_Closing_Date'     => '2026-12-19',
			'Created_Time'              => '2026-08-12T09:30:00+05:30',
			'Modified_Time'             => '2026-09-01T11:00:00+05:30',
			'Publish_in_Career_Website' => true,
		),
		$fields
	);
}

/**
 * Wrap a description in the markup Zoho's rich-text editor produces.
 *
 * @param string $intro  Opening paragraph.
 * @param array  $points Bullet points.
 * @return string
 */
function jszr_demo_description( $intro, array $points ) {
	$html = '<p>' . $intro . '</p><p><strong>What you will do</strong></p><ul>';

	foreach ( $points as $point ) {
		$html .= '<li>' . $point . '</li>';
	}

	return $html . '</ul>';
}

$jszr_demo = array(
	jszr_demo_record(
		array(
			'id'                    => 'DEMO000000000000001',
			'Posting_Title'         => 'Senior Backend Engineer',
			'Job_Opening_ID'        => 'ENG-104',
			'Job_Description'       => jszr_demo_description(
				'We are looking for a backend engineer to help us scale the platform that our customers rely on every day.',
				array(
					'Design and build services in PHP and Go',
					'Own the reliability of the systems you ship',
					'Review code and mentor engineers earlier in their career',
				)
			),
			'Job_Summary'           => 'Design and build the services behind our platform, and own their reliability in production.',
			'Department_Name'       => array(
				'id'   => '900',
				'name' => 'Engineering',
			),
			'Job_Type'              => 'Full time',
			'Work_Experience'       => '5 - 8 years',
			'Industry'              => 'Software',
			'City'                  => 'Chennai',
			'State'                 => 'Tamil Nadu',
			'Salary'                => 'INR 2400000 - 3200000 per year',
			'Number_of_Positions'   => '2',
			'Website'               => 'https://example.com/apply/eng-104',
		)
	),
	jszr_demo_record(
		array(
			'id'                    => 'DEMO000000000000002',
			'Posting_Title'         => 'Product Designer',
			'Job_Opening_ID'        => 'DES-021',
			'Job_Description'       => jszr_demo_description(
				'Join a small design team that owns the whole experience, from the first sketch to the shipped interface.',
				array(
					'Turn messy problems into clear interfaces',
					'Run research sessions with real customers',
					'Keep the design system honest as the product grows',
				)
			),
			'Job_Summary'           => 'Own the experience end to end, from first sketch to shipped interface.',
			'Department_Name'       => array(
				'id'   => '901',
				'name' => 'Design',
			),
			'Job_Type'              => 'Full time',
			'Work_Experience'       => '3 - 5 years',
			'Industry'              => 'Software',
			'City'                  => 'Bengaluru',
			'State'                 => 'Karnataka',
			'Remote_Job'            => true,
			'Salary'                => 'INR 1800000 - 2400000 per year',
			'Expected_Closing_Date' => '2026-11-30',
			'Website'               => 'https://example.com/apply/des-021',
		)
	),
	jszr_demo_record(
		array(
			'id'                  => 'DEMO000000000000003',
			'Posting_Title'       => 'Technical Recruiter',
			'Job_Opening_ID'      => 'TAL-008',
			'Job_Description'     => jszr_demo_description(
				'Help us hire the next fifty people without losing what makes the team work.',
				array(
					'Own engineering and design pipelines end to end',
					'Partner with hiring managers on what "good" means',
					'Keep candidates informed at every stage',
				)
			),
			'Job_Summary'         => 'Own our engineering and design hiring pipelines end to end.',
			'Department_Name'     => array(
				'id'   => '902',
				'name' => 'People',
			),
			'Job_Type'            => 'Full time',
			'Work_Experience'     => '2 - 5 years',
			'Industry'            => 'Software',
			'City'                => 'Chennai',
			'State'               => 'Tamil Nadu',
			'Salary'              => 'INR 1200000 - 1600000 per year',
			'Website'             => 'https://example.com/apply/tal-008',
		)
	),
	jszr_demo_record(
		array(
			'id'                    => 'DEMO000000000000004',
			'Posting_Title'         => 'Customer Success Manager',
			'Job_Opening_ID'        => 'CS-033',
			'Job_Description'       => jszr_demo_description(
				'Be the person our largest customers call first, and the reason they stay.',
				array(
					'Run onboarding for new enterprise accounts',
					'Turn recurring support themes into product feedback',
					'Own renewal conversations alongside sales',
				)
			),
			'Job_Summary'           => 'Be the person our largest customers call first.',
			'Department_Name'       => array(
				'id'   => '903',
				'name' => 'Customer Success',
			),
			'Job_Type'              => 'Full time',
			'Work_Experience'       => '3 - 5 years',
			'Industry'              => 'Software',
			'City'                  => 'Remote',
			'Country'               => 'India',
			'Remote_Job'            => true,
			'Salary'                => 'INR 1400000 - 1900000 per year',
			'Expected_Closing_Date' => '2027-01-31',
			'Website'               => 'https://example.com/apply/cs-033',
		)
	),
	jszr_demo_record(
		array(
			'id'                  => 'DEMO000000000000005',
			'Posting_Title'       => 'Engineering Intern',
			'Job_Opening_ID'      => 'ENG-110',
			'Job_Description'     => jszr_demo_description(
				'A six month internship on a team that will let you ship to production in your first fortnight.',
				array(
					'Work on a real feature with a real mentor',
					'Write tests for the code you ship',
					'Present what you built at the end of the term',
				)
			),
			'Job_Summary'         => 'A six month internship on a team that ships.',
			'Department_Name'     => array(
				'id'   => '900',
				'name' => 'Engineering',
			),
			'Job_Type'            => 'Internship',
			'Work_Experience'     => '0 - 1 years',
			'Industry'            => 'Software',
			'City'                => 'Chennai',
			'State'               => 'Tamil Nadu',
			'Salary'              => 'INR 40000 per month',
			'Website'             => 'https://example.com/apply/eng-110',
		)
	),
	jszr_demo_record(
		array(
			'id'                    => 'DEMO000000000000006',
			'Posting_Title'         => 'Data Analyst',
			'Job_Opening_ID'        => 'DAT-015',
			'Job_Description'       => jszr_demo_description(
				'Answer the questions the business has not learned to ask yet.',
				array(
					'Build the reporting the whole company reads on Monday',
					'Keep the warehouse models trustworthy',
					'Work with every team, and say no to some of them',
				)
			),
			'Job_Summary'           => 'Build the reporting the whole company reads on Monday.',
			'Department_Name'       => array(
				'id'   => '904',
				'name' => 'Data',
			),
			'Job_Type'              => 'Contract',
			'Work_Experience'       => '2 - 5 years',
			'Industry'              => 'Software',
			'City'                  => 'Hyderabad',
			'State'                 => 'Telangana',
			'Salary'                => 'INR 1500000 - 2000000 per year',
			'Job_Opening_Status'    => 'Filled',
			'Expected_Closing_Date' => '2026-08-30',
			'Website'               => 'https://example.com/apply/dat-015',
		)
	),
);

if ( in_array( 'cleanup', (array) ( $args ?? array() ), true ) ) {
	$removed = 0;

	foreach ( $jszr_demo as $record ) {
		$post_id = Job::find_by_zoho_id( $record['id'] );

		if ( $post_id ) {
			wp_delete_post( $post_id, true );
			++$removed;
		}
	}

	WP_CLI::success( sprintf( 'Removed %d demo job(s).', $removed ) );

	return;
}

$jszr_sync = plugin()->sync();

foreach ( $jszr_demo as $record ) {
	$action = $jszr_sync->process_record( $record );

	WP_CLI::log( sprintf( '%s -> %s', $record['Posting_Title'], $action ) );
}

WP_CLI::success( sprintf( 'Seeded %d demo job(s).', count( $jszr_demo ) ) );
