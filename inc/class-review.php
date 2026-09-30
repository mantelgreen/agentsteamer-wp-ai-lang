<?php
/**
 * Review queue for AI-proposed translations.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, lists, applies and rejects AI-generated changes.
 */
class AgentSteamer_Lang_Review {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asl_reviews';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(32) NOT NULL DEFAULT 'content',
			old_value longtext,
			new_value longtext,
			summary text,
			changes longtext,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at datetime DEFAULT NULL,
			applied_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_post_agentsteamer_lang_apply_review', array( $this, 'handle_apply' ) );
			add_action( 'admin_post_agentsteamer_lang_reject_review', array( $this, 'handle_reject' ) );
			add_action( 'admin_post_agentsteamer_lang_rollback_review', array( $this, 'handle_rollback' ) );
		}
	}

	/**
	 * Add an entry.
	 *
	 * @param int    $post_id   Source post id.
	 * @param string $field     Field.
	 * @param string $old       Old value.
	 * @param string $new       New value.
	 * @param string $summary   Summary.
	 * @param array  $changes   Changes.
	 * @param int    $target_id Target post id.
	 * @return int
	 */
	public function add( $post_id, $field, $old, $new, $summary = '', $changes = array(), $target_id = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'post_id'    => (int) $post_id,
				'target_id'  => (int) $target_id,
				'field'      => $field,
				'old_value'  => $old,
				'new_value'  => $new,
				'summary'    => $summary,
				'changes'    => wp_json_encode( $changes, JSON_UNESCAPED_UNICODE ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a review.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Recent reviews.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count pending reviews.
	 *
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Apply a review (writes to the target draft).
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function apply( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'pending' !== $review['status'] ) {
			return false;
		}

		$target_id = (int) $review['target_id'] ? (int) $review['target_id'] : (int) $review['post_id'];
		$field     = $review['field'];
		$new       = $review['new_value'];

		if ( 'content' === $field ) {
			wp_update_post(
				array(
					'ID'           => $target_id,
					'post_content' => $new,
				)
			);
		} else {
			update_post_meta( $target_id, agentsteamer_lang_meta_key( $field ), $new );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'applied',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/**
	 * Reject a review.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function reject( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'pending' !== $review['status'] ) {
			return false;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'rejected',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/**
	 * Roll back an applied review.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function rollback( $id ) {
		$review = $this->get( $id );
		if ( ! $review || 'applied' !== $review['status'] ) {
			return false;
		}
		$target_id = (int) $review['target_id'] ? (int) $review['target_id'] : (int) $review['post_id'];
		$field     = $review['field'];

		if ( 'content' === $field ) {
			wp_update_post(
				array(
					'ID'           => $target_id,
					'post_content' => $review['old_value'],
				)
			);
		} else {
			update_post_meta( $target_id, agentsteamer_lang_meta_key( $field ), $review['old_value'] );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'     => 'rolled_back',
				'applied_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/**
	 * Handle apply.
	 */
	public function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_lang_apply_review_' . $id );
		$this->apply( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-reviews&applied=1' ) );
		exit;
	}

	/**
	 * Handle reject.
	 */
	public function handle_reject() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_lang_reject_review_' . $id );
		$this->reject( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-reviews&rejected=1' ) );
		exit;
	}

	/**
	 * Handle rollback.
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_lang_rollback_review_' . $id );
		$this->rollback( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-reviews&rolledback=1' ) );
		exit;
	}

	/**
	 * Render the review queue page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$rows = $this->get_recent( 50 );
		?>
		<div class="wrap asl-wrap">
			<h1><?php esc_html_e( '翻译审阅队列', 'agentsteamer-lang' ); ?></h1>
			<p class="asl-sub"><?php esc_html_e( 'AI 翻译先进入这里，确认后再写入语言稿。', 'agentsteamer-lang' ); ?></p>
			<?php if ( isset( $_GET['applied'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已应用。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rejected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已拒绝。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rolledback'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已回滚。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="asl-card"><p><?php esc_html_e( '暂无翻译记录。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( $rows as $row ) : ?>
				<?php
				$source  = get_post( (int) $row['post_id'] );
				$target  = get_post( (int) $row['target_id'] );
				$changes = json_decode( (string) $row['changes'], true );
				$status  = $row['status'];
				$badge   = 'pending' === $status ? 'asl-badge-warn' : ( 'applied' === $status ? 'asl-badge-ok' : 'asl-badge' );
				?>
				<div class="asl-card">
					<p>
						<span class="asl-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span>
						<strong><?php echo esc_html( $source ? $source->post_title : '(#)' . $row['post_id'] ); ?></strong>
						<?php if ( $target ) : ?>
							<span class="asl-hint">→ <?php echo esc_html( $target->post_title ); ?></span>
						<?php endif; ?>
						<span class="asl-hint"><?php echo esc_html( $row['created_at'] ); ?></span>
					</p>
					<?php if ( ! empty( $row['summary'] ) ) : ?>
						<p><strong><?php esc_html_e( '摘要：', 'agentsteamer-lang' ); ?></strong><?php echo esc_html( $row['summary'] ); ?></p>
					<?php endif; ?>
					<?php if ( is_array( $changes ) && ! empty( $changes ) ) : ?>
						<ul class="asl-audit-items">
							<?php foreach ( $changes as $change ) : ?>
								<li><?php echo esc_html( $change ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( 'pending' === $status ) : ?>
						<div class="asl-diff">
							<div class="asl-diff-col">
								<h4><?php esc_html_e( '修改前', 'agentsteamer-lang' ); ?></h4>
								<pre><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $row['old_value'] ), 160, '…' ) ); ?></pre>
							</div>
							<div class="asl-diff-col">
								<h4><?php esc_html_e( '修改后', 'agentsteamer-lang' ); ?></h4>
								<pre><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $row['new_value'] ), 160, '…' ) ); ?></pre>
							</div>
						</div>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_apply_review&id=' . $row['id'] ), 'agentsteamer_lang_apply_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '应用', 'agentsteamer-lang' ); ?></a>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_reject_review&id=' . $row['id'] ), 'agentsteamer_lang_reject_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '拒绝', 'agentsteamer-lang' ); ?></a>
						</p>
					<?php elseif ( 'applied' === $status ) : ?>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_rollback_review&id=' . $row['id'] ), 'agentsteamer_lang_rollback_review_' . $row['id'] ) ); ?>"><?php esc_html_e( '回滚', 'agentsteamer-lang' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
