<?php

/**
 * Query for posts with `pmp_guid` -- an indication that the post was pulled from PMP
 *
 * @since 0.1
 */
function pmp_get_pmp_posts() {
	$sdk = new SDKWrapper();
	$me = $sdk->fetchUser('me');

	$meta_args = array(
		'relation' => 'AND',
		array(
			'key' => 'pmp_guid',
			'compare' => 'EXISTS'
		),
		array(
			'key' => 'pmp_owner',
			'compare' => '!=',
			'value' => $me->attributes->guid
		)
	);

	$query = new WP_Query(array(
		'meta_query' => $meta_args,
		'posts_per_page' => -1,
		'post_status' => 'any'
	));

	return $query->posts;
}

/**
 * For each PMP post in the WP database, fetch the corresponding Doc from PMP and check if
 * the WP post differs from the PMP Doc. If it does differ, update the post in the WP database.
 *
 * @since 0.1
 */
function pmp_get_updates() {
	$posts = pmp_get_pmp_posts();

	$sdk = new SDKWrapper();

	foreach ($posts as $post) {
		$custom_fields = get_post_custom($post->ID);

		if (empty($custom_fields['pmp_subscribe_to_updates']))
			$subscribe_to_updates = 'on';
		else
			$custom_fields['pmp_subscribe_to_updates'][0];

		if ($subscribe_to_updates == 'on')
			$subscribed = true;
		else
			$subscribed = false;

		if ($subscribed) {
			$guid = $custom_fields['pmp_guid'][0];
			$results = $sdk->query2json('fetchDoc', $guid);
			if (count($results['items']) > 0) {
				$doc = $results['items'][0];
				if (pmp_needs_update($post, $doc))
					pmp_update_post($post, $doc);
			}
		}
	}
}

/**
 * Compare the md5 hash of a WP post and PMP Doc to determine whether or not the WP post is different
 * from PMP and therefore needs updating.
 *
 * @since 0.1
 */
function pmp_needs_update($wp_post, $pmp_doc) {
	$post_modified = get_post_meta($wp_post->ID, 'pmp_modified', true);
	if ($pmp_doc['attributes']['modified'] !== $post_modified)
		return true;
	return false;
}

/**
 * Update an existing WP post which was originally pulled from PMP with the Doc data from PMP.
 *
 * @since 0.1
 */
function pmp_update_post($wp_post, $pmp_doc) {
	$data = $pmp_doc;

	$post_data = array(
		'ID' => $wp_post->ID,
		'post_title' => $data['attributes']['title'],
		'post_content' => $data['attributes']['contentencoded'],
		'post_excerpt' => $data['attributes']['teaser'],
		'post_date' => date('Y-m-d H:i:s', strtotime($data['attributes']['published']))
	);

	$updated_post = wp_update_post($post_data);

	if (is_wp_error($updated_post))
		return $updated_post;

	$post_meta = array(
		'pmp_guid' => $data['attributes']['guid'],
		'pmp_created' => $data['attributes']['created'],
		'pmp_modified' => $data['attributes']['modified'],
		'pmp_byline' => $data['attributes']['byline'],
		'pmp_published' => $data['attributes']['published'],
		'pmp_owner' => SDKWrapper::guid4href($data['links']['owner'][0]['href'])
	);

	foreach ($post_meta as $key => $value)
		update_post_meta($updated_post, $key, $value);
}
