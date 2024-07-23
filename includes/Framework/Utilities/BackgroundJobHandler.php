<?php
/**
 * Facebook for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework\Utilities;

use WooCommerce\Facebook\Framework\Plugin\Compatibility;

defined('ABSPATH') || exit;

/**
 * SkyVerge WordPress Background Job Handler class
 *
 * Based on the wonderful WP_Background_Process class by deliciousbrains:
 * https://github.com/A5hleyRich/wp-background-processing
 *
 * Subclasses SV_WP_Async_Request. Instead of the concept of `batches` used in
 * the Delicious Brains' version, however, this takes a more object-oriented approach
 * of background `jobs`, allowing greater control over manipulating job data and
 * processing.
 *
 * A batch implicitly expected an array of items to process, whereas a job does
 * not expect any particular data structure (although it does default to
 * looping over job data) and allows subclasses to provide their own
 * processing logic.
 *
 * # Sample usage:
 *
 * $background_job_handler = new SV_WP_Background_Job_Handler();
 * $job = $background_job_handler->create_job( $attrs );
 * $background_job_handler->dispatch();
 *
 * @since 4.4.0
 */
abstract class BackgroundJobHandler extends AsyncRequest
{
	/** @var string async request prefix */
	protected $prefix = 'sv_wp';

	/** @var string async request action */
	protected $action = 'background_job';

	/** @var string data key */
	protected $data_key = 'data';

	/** @var int start time of current process */
	protected $start_time = 0;

	/** @var string cron hook identifier */
	protected $cron_hook_identifier;

	/** @var string cron interval identifier */
	protected $cron_interval_identifier;


	/**
	 * Initiate new background job handler
	 *
	 * @since 4.4.0
	 */
	public function __construct()
	{
		parent::__construct();
		$this->cron_hook_identifier = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';
	}


	/**
	 * Dispatch
	 *
	 * @return array|\WP_Error
	 * @since 4.4.0
	 */
	public function dispatch()
	{
		// schedule the cron healthcheck
		$this->schedule_event();

		// perform remote post
		return parent::dispatch();
	}


	/**
	 * Check whether job queue is empty or not
	 *
	 * @return bool True if queue is empty, false otherwise
	 * @since 4.4.0
	 */
	protected function is_queue_empty()
	{
		global $wpdb;

		$key = $this->identifier . '_job_%';

		// only queued or processing jobs count
		$queued = '%"status":"queued"%';
		$processing = '%"status":"processing"%';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND ( option_value LIKE %s OR option_value LIKE %s )",
				$key,
				$queued,
				$processing
			)
		);

		return intval($count) === 0;
	}


	/**
	 * Lock process
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 *
	 * @since 4.4.0
	 */
	protected function lock_process()
	{
		// set start time of current process
		$this->start_time = time();
		// set lock duration to 1 minute by default
		$lock_duration = (property_exists($this, 'queue_lock_time')) ? $this->queue_lock_time : 60;
		/**
		 * Filter the queue lock time
		 *
		 * @param int $lock_duration Lock duration in seconds
		 * @since 4.4.0
		 */
		$lock_duration = apply_filters("{$this->identifier}_queue_lock_time", $lock_duration);
		set_transient("{$this->identifier}_process_lock", microtime(), $lock_duration);
	}


	/**
	 * Unlock process
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return BackgroundJobHandler
	 * @since 4.4.0
	 */
	protected function unlock_process()
	{
		delete_transient("{$this->identifier}_process_lock");
		return $this;
	}


	/**
	 * Check if memory limit is exceeded
	 *
	 * Ensures the background job handler process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool True if exceeded memory limit, false otherwise
	 * @since 4.4.0
	 *
	 */
	protected function memory_exceeded()
	{
		$memory_limit = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage(true);
		$return = false;

		if ($current_memory >= $memory_limit) {
			$return = true;
		}

		/**
		 * Filter whether memory limit has been exceeded or not
		 *
		 * @param bool $exceeded
		 * @since 4.4.0
		 *
		 */
		return apply_filters("{$this->identifier}_memory_exceeded", $return);
	}


	/**
	 * Get memory limit
	 *
	 * @return int memory limit in bytes
	 * @since 4.4.0
	 *
	 */
	protected function get_memory_limit()
	{
		if (function_exists('ini_get')) {
			$memory_limit = ini_get('memory_limit');
		} else {
			// sensible default
			$memory_limit = '128M';
		}

		if (!$memory_limit || -1 === (int)$memory_limit) {
			// unlimited, set to 32GB
			$memory_limit = '32G';
		}

		return Compatibility::convert_hr_to_bytes($memory_limit);
	}


	/**
	 * Check whether request time limit has been exceeded or not
	 *
	 * Ensures the background job handler never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool True, if time limit exceeded, false otherwise
	 * @since 4.4.0
	 *
	 */
	protected function time_exceeded()
	{
		/**
		 * Filter default time limit for background job execution, defaults to
		 * 20 seconds
		 *
		 * @param int $time Time in seconds
		 * @since 4.4.0
		 *
		 */
		$finish = $this->start_time + apply_filters("{$this->identifier}_default_time_limit", 20);
		$return = false;

		if (time() >= $finish) {
			$return = true;
		}

		/**
		 * Filter whether maximum execution time has exceeded or not
		 *
		 * @param bool $exceeded true if execution time exceeded, false otherwise
		 * @since 4.4.0
		 */
		return apply_filters("{$this->identifier}_time_exceeded", $return);
	}


	/**
	 * Create a background job
	 *
	 * Delicious Brains' versions alternative would be using ->data()->save().
	 * Allows passing in any kind of job attributes, which will be available at item data processing time.
	 * This allows sharing common options between items without the need to repeat
	 * the same information for every single item in queue.
	 *
	 * Instead of returning self, returns the job instance, which gives greater
	 * control over the job.
	 *
	 * @param array|mixed $attrs Job attributes.
	 * @return \stdClass|object|null
	 * @since 4.4.0
	 *
	 */
	public function create_job($attrs)
	{
		global $wpdb;

		if (empty($attrs)) {
			return null;
		}

		// generate a unique ID for the job
		$job_id = md5(microtime() . wp_rand());

		/**
		 * Filter new background job attributes
		 *
		 * @param array $attrs Job attributes
		 * @param string $id Job ID
		 * @since 4.4.0
		 *
		 */
		$attrs = apply_filters("{$this->identifier}_new_job_attrs", $attrs, $job_id);

		// ensure a few must-have attributes
		$attrs = wp_parse_args(
			[
				'id' => $job_id,
				'created_at' => current_time('mysql'),
				'created_by' => get_current_user_id(),
				'status' => 'queued',
			],
			$attrs
		);

		$wpdb->insert(
			$wpdb->options,
			[
				'option_name' => "{$this->identifier}_job_{$job_id}",
				'option_value' => wp_json_encode($attrs),
				'autoload' => 'no',
			]
		);

		$job = new \stdClass();

		foreach ($attrs as $key => $value) {
			$job->{$key} = $value;
		}

		/**
		 * Runs when a job is created.
		 *
		 * @param \stdClass|object $job the created job
		 * @since 4.4.0
		 *
		 */
		do_action("{$this->identifier}_job_created", $job);

		return $job;
	}


	/**
	 * Get a job (by default the first in the queue)
	 *
	 * @param string $id Optional. Job ID. Will return first job in queue if not
	 *                   provided. Will not return completed or failed jobs from queue.
	 * @return \stdClass|object|null The found job object or null
	 * @since 4.4.0
	 *
	 */
	public function get_job($id = null)
	{
		global $wpdb;

		if (!$id) {

			$key = $this->identifier . '_job_%';
			$queued = '%"status":"queued"%';
			$processing = '%"status":"processing"%';

			$results = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value
					FROM {$wpdb->options}
					WHERE option_name LIKE %s
					AND ( option_value LIKE %s OR option_value LIKE %s )
					ORDER BY option_id ASC
					LIMIT 1",
					$key,
					$queued,
					$processing
				)
			);
		} else {
			$results = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value
					FROM {$wpdb->options}
					WHERE option_name = %s",
					"{$this->identifier}_job_{$id}"
				)
			);
		}

		if (!empty($results)) {
			$job = new \stdClass();
			foreach (json_decode($results, true) as $key => $value) {
				$job->{$key} = $value;
			}
		} else {
			return null;
		}

		/**
		 * Filters the job as returned from the database.
		 *
		 * @param \stdClass|object $job
		 * @since 4.4.0
		 *
		 */
		return apply_filters("{$this->identifier}_returned_job", $job);
	}


	/**
	 * Gets jobs.
	 *
	 * @param array $args {
	 *     Optional. An array of arguments
	 *
	 * @type string|array $status Job status(es) to include
	 * @type string $order ASC or DESC. Defaults to DESC
	 * @type string $orderby Field to order by. Defaults to option_id
	 * }
	 * @return \stdClass[]|object[]|null Found jobs or null if none found
	 * @since 4.4.2
	 *
	 */
	public function get_jobs($args = [])
	{
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'order' => 'DESC',
				'orderby' => 'option_id',
			]
		);

		$replacements = [$this->identifier . '_job_%'];
		$status_query = '';

		// prepare status query
		if (!empty($args['status'])) {
			$statuses = (array)$args['status'];
			$placeholders = [];
			foreach ($statuses as $status) {
				$placeholders[] = '%s';
				$replacements[] = '%"status":"' . sanitize_key($status) . '"%';
			}
			$status_query = 'AND ( option_value LIKE ' . implode(' OR option_value LIKE ', $placeholders) . ' )';
		}

		// prepare sorting vars
		$order = sanitize_key($args['order']);
		$orderby = sanitize_key($args['orderby']);

		// put it all together now
		$query = $wpdb->prepare(
		/* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s {$status_query} ORDER BY {$orderby} {$order}",
			$replacements
		);

		/* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared */
		$results = $wpdb->get_col($query);

		if (empty($results)) {
			return null;
		}

		$jobs = [];
		foreach ($results as $result) {
			$job = new \stdClass();
			foreach (json_decode($result, true) as $key => $value) {
				$job->{$key} = $value;
			}
			/** This filter is documented above */
			$job = apply_filters("{$this->identifier}_returned_job", $job);
			$jobs[] = $job;
		}

		return $jobs;
	}


	/**
	 * Handles jobs.
	 *
	 * Process jobs while remaining within server memory and time limit constraints.
	 *
	 * @throws \Exception Upon error.
	 * @since 4.4.0
	 *
	 */
	protected function handle()
	{
		$this->lock_process();

		do {
			// Get next job in the queue
			$job = $this->get_job();
			// handle PHP errors from here on out
			register_shutdown_function([$this, 'handle_shutdown'], $job);
			// Start processing
			$this->process_job($job);
		} while (!$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty());

		$this->unlock_process();

		// Start next job or complete process
		if (!$this->is_queue_empty()) {
			$this->dispatch();
		} else {
			$this->complete();
		}

		wp_die();
	}


	/**
	 * Process a job
	 *
	 * Default implementation is to loop over job data and passing each item to
	 * the item processor. Subclasses are, however, welcome to override this method
	 * to create totally different job processing implementations - see
	 * WC_CSV_Import_Suite_Background_Import in CSV Import for an example.
	 *
	 * If using the default implementation, the job must have a $data_key property set.
	 * Subclasses can override the data key, but the contents must be an array which
	 * the job processor can loop over. By default, the data key is `data`.
	 *
	 * If no data is set, the job will completed right away.
	 *
	 * @param \stdClass|object $job
	 * @param int $items_per_batch number of items to process in a single request. Defaults to unlimited.
	 * @return \stdClass $job
	 * @throws \Exception When job data is incorrect.
	 * @since 4.4.0
	 *
	 */
	public function process_job($job, $items_per_batch = null)
	{
		if (!$this->start_time) {
			$this->start_time = time();
		}

		// Indicate that the job has started processing
		if ('processing' !== $job->status) {

			$job->status = 'processing';
			$job->started_processing_at = current_time('mysql');

			$job = $this->update_job($job);
		}

		$data_key = $this->data_key;

		if (!isset($job->{$data_key})) {
			/* translators: Placeholders: %s - user-friendly error message */
			throw new \Exception(sprintf(__('Job data key "%s" not set', 'facebook-for-woocommerce'), $data_key));
		}

		if (!is_array($job->{$data_key})) {
			/* translators: Placeholders: %s - user-friendly error message */
			throw new \Exception(sprintf(__('Job data key "%s" is not an array', 'facebook-for-woocommerce'), $data_key));
		}

		$data = $job->{$data_key};

		$job->total = count($data);

		// progress indicates how many items have been processed, it
		// does NOT indicate the processed item key in any way
		if (!isset($job->progress)) {
			$job->progress = 0;
		}

		// skip already processed items
		if ($job->progress && !empty($data)) {
			$data = array_slice($data, $job->progress, null, true);
		}

		// loop over unprocessed items and process them
		if (!empty($data)) {

			$processed = 0;
			$items_per_batch = (int)$items_per_batch;

			foreach ($data as $item) {

				// process the item
				$this->process_item($item, $job);

				$processed++;
				$job->progress++;

				// update job progress
				$job = $this->update_job($job);

				// job limits reached
				if (($items_per_batch && $processed >= $items_per_batch) || $this->time_exceeded() || $this->memory_exceeded()) {
					break;
				}
			}
		}

		// complete current job
		if ($job->progress >= count($job->{$data_key})) {
			$job = $this->complete_job($job);
		}

		return $job;
	}


	/**
	 * Update job attrs
	 *
	 * @param \stdClass|object|string $job Job instance or ID
	 * @return \stdClass|object|false on failure
	 * @since 4.4.0
	 *
	 */
	public function update_job($job)
	{
		if (is_string($job)) {
			$job = $this->get_job($job);
		}
		if (!$job) {
			return false;
		}
		$job->updated_at = current_time('mysql');
		$this->update_job_option($job);
		/**
		 * Runs when a job is updated.
		 *
		 * @param \stdClass|object $job the updated job
		 * @since 4.4.0
		 *
		 */
		do_action("{$this->identifier}_job_updated", $job);
		return $job;
	}


	/**
	 * Handles job completion.
	 *
	 * @param \stdClass|object|string $job Job instance or ID
	 * @return \stdClass|object|false on failure
	 * @since 4.4.0
	 *
	 */
	public function complete_job($job)
	{
		if (is_string($job)) {
			$job = $this->get_job($job);
		}
		if (!$job) {
			return false;
		}
		$job->status = 'completed';
		$job->completed_at = current_time('mysql');
		$this->update_job_option($job);
		/**
		 * Runs when a job is completed.
		 *
		 * @param \stdClass|object $job the completed job
		 * @since 4.4.0
		 *
		 */
		do_action("{$this->identifier}_job_complete", $job);
		return $job;
	}


	/**
	 * Handle job failure
	 *
	 * Default implementation does not call this method directly, but it's
	 * provided as a convenience method for subclasses that may call this to
	 * indicate that a particular job has failed for some reason.
	 *
	 * @param \stdClass|object|string $job Job instance or ID
	 * @param string $reason Optional. Reason for failure.
	 * @return \stdClass|false on failure
	 * @since 4.4.0
	 *
	 */
	public function fail_job($job, $reason = '')
	{
		if (is_string($job)) {
			$job = $this->get_job($job);
		}
		if (!$job) {
			return false;
		}
		$job->status = 'failed';
		$job->failed_at = current_time('mysql');
		if ($reason) {
			$job->failure_reason = $reason;
		}
		$this->update_job_option($job);
		/**
		 * Runs when a job is failed.
		 *
		 * @param \stdClass|object $job the failed job
		 * @since 4.4.0
		 *
		 */
		do_action("{$this->identifier}_job_failed", $job);
		return $job;
	}


	/**
	 * Handle job queue completion
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 4.4.0
	 */
	protected function complete()
	{
		// unschedule the cron healthcheck
		$this->clear_scheduled_event();
	}


	/**
	 * Schedule cron health check event
	 *
	 * @since 4.4.0
	 */
	protected function schedule_event()
	{
		if (!wp_next_scheduled($this->cron_hook_identifier)) {
			// schedule the health check to fire after 30 seconds from now, as to not create a race condition
			// with job process lock on servers that fire & handle cron events instantly
			wp_schedule_event(time() + 30, $this->cron_interval_identifier, $this->cron_hook_identifier);
		}
	}


	/**
	 * Clear scheduled health check event
	 *
	 * @since 4.4.0
	 */
	protected function clear_scheduled_event()
	{
		$timestamp = wp_next_scheduled($this->cron_hook_identifier);
		if ($timestamp) {
			wp_unschedule_event($timestamp, $this->cron_hook_identifier);
		}
	}


	/**
	 * Process an item from job data
	 *
	 * Implement this method to perform any actions required on each
	 * item in job data.
	 *
	 * @param mixed $item Job data item to iterate over
	 * @param \stdClass|object $job Job instance
	 * @return mixed
	 * @since 4.4.2
	 *
	 */
	abstract protected function process_item($item, $job);


	/**
	 * Handles PHP shutdown, say after a fatal error.
	 *
	 * @param \stdClass|object $job the job being processed
	 * @since 4.5.0
	 *
	 */
	public function handle_shutdown($job)
	{
		$error = error_get_last();
		// if shutting down because of a fatal error, fail the job
		if ($error && E_ERROR === $error['type']) {
			$this->fail_job($job, $error['message']);
			$this->unlock_process();
		}
	}


	/**
	 * Update a job option in options database.
	 *
	 * @param \stdClass|object $job the job instance to update in database
	 * @return int|bool number of rows updated or false on failure, see wpdb::update()
	 * @since 4.6.3
	 *
	 */
	private function update_job_option($job)
	{
		global $wpdb;

		return $wpdb->update(
			$wpdb->options,
			['option_value' => wp_json_encode($job)],
			['option_name' => "{$this->identifier}_job_{$job->id}"]
		);
	}
}
