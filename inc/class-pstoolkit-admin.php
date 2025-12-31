<?php
if ( ! class_exists( 'PSToolkit_Admin' ) ) {
	require_once dirname( __FILE__ ) . '/class-pstoolkit-base.php';
	require_once dirname( __FILE__ ) . '/class-pstoolkit-admin-stats.php';
	class PSToolkit_Admin extends PSToolkit_Base {

		var $modules    = array();
		var $plugin_msg = array();

		/**
		 * Default messages.
		 *
		 * @since 1.8.5
		 */
		var $messages = array();

		/**
		 * Stats
		 *
		 * @since 2.3.0
		 */
		private $stats = null;

		/**
		 * module
		 *
		 * @since 1.0.0
		 */
		private $module = '';

		/**
		 * Show Welcome Dialog
		 *
		 * @since 1.0.0
		 */
		private $show_welcome_dialog = false;

		/**
		 * Top page slug
		 */
		private $top_page_slug;

		/**
		 * Messages storing
		 *
		 * @since 3.1.0
		 */
		private $messages_option_name = 'pstoolkit_messages';

		/**
		 * Is PSToolkit admin menu shown or not
		 *
		 * @var bool
		 */
		private static $is_show_admin_menu = false;

		public function __construct() {
			parent::__construct();
			/**
			 * set and sanitize variables
			 */
			add_action( 'plugins_loaded', array( $this, 'set_and_sanitize_variables' ), 2 );
			/**
			 * run stats
			 */
			$this->stats = new PSToolkit_Admin_Stats();
			foreach ( $this->configuration as $key => $data ) {
				$is_avaialble = $this->can_load_module( $data );
				if ( ! $is_avaialble ) {
					continue;
				}
				if ( isset( $data['disabled'] ) && $data['disabled'] ) {
					continue;
				}
				$this->modules[ $key ] = $data['module'];
			}
			/**
			 * Filter allow to turn off available modules.
			 *
			 * @since 1.9.4
			 *
			 * @param array $modules available modules array.
			 */
			$this->modules = apply_filters( 'pstoolkit_available_modules', $this->modules );
			add_action( 'plugins_loaded', array( $this, 'load_modules' ), 11 );
			add_action( 'plugins_loaded', array( $this, 'setup_translation' ) );
			add_action( 'network_admin_menu', array( $this, 'network_admin_page' ) );
			add_action( 'admin_menu', array( $this, 'admin_page' ) );
			add_filter( 'admin_title', array( $this, 'admin_title' ), 10, 2 );
			/**
			 * AJAX
			 */
			add_action( 'wp_ajax_pstoolkit_toggle_module', array( $this, 'toggle_module' ) );
			add_action( 'wp_ajax_pstoolkit_reset_module', array( $this, 'ajax_reset_module' ) );
			add_action( 'wp_ajax_pstoolkit_manage_all_modules', array( $this, 'ajax_bulk_modules' ) );
			add_action( 'wp_ajax_pstoolkit_module_copy_settings', array( $this, 'ajax_copy_settings' ) );
			add_action( 'wp_ajax_pstoolkit_welcome_get_modules', array( $this, 'ajax_welcome' ) );
			add_filter( 'pstoolkit_admin_messages_array', array( $this, 'add_admin_notices' ) );
			add_action( 'wp_ajax_pstoolkit_new_feature_dismiss', array( $this, 'new_feature_dismiss' ) );
			/**
			 * default messages
			 */
			$this->messages = array(
				'success'               => 'Erfolg! Deine Änderungen wurden erfolgreich gespeichert!',
				'fail'                  => 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.',
				'reset-section-success' => 'Abschnitt wurde auf die Standardeinstellungen zurückgesetzt.',
				'wrong'                 => 'Etwas ist schief gelaufen!',
				'security'              => 'Nee! Sicherheitsüberprüfung fehlgeschlagen!',
				'missing'               => 'Fehlende benötigte Daten!',
				'wrong_userlogin'       => 'Diese Benutzeranmeldung existiert nicht!',
			);
			/**
			 * remove default footer
			 */
			add_filter( 'admin_footer_text', array( $this, 'remove_default_footer' ), PHP_INT_MAX );
			/**
			 * upgrade
			 *
			 * @since 1.0.0
			 */
			add_action( 'init', array( $this, 'upgrade' ) );
			/**
			 * Add pstoolkit class to admin body
			 */
			add_filter( 'admin_body_class', array( $this, 'add_pstoolkit_admin_body_class' ), PHP_INT_MAX );
			/**
			 * Add import/export modules instantly on
			 */
			add_filter( 'ub_get_option-pstoolkit_activated_modules', array( $this, 'add_instant_modules' ), 10, 3 );
			/**
			 * Allow to uload SVG files.
			 *
			 * @since 1.8.9
			 */
			add_filter( 'upload_mimes', array( $this, 'add_svg_to_allowed_mime_types' ) );
			/**
			 * Add sui-wrap classes
			 *
			 * @since 3.0.6
			 */
			add_filter( 'pstoolkit_sui_wrap_class', array( $this, 'add_sui_wrap_classes' ) );
			/**
			 * Delete image from modules, when it is deleted from ClassicPress
			 *
			 * @since 3.1.0
			 */
			add_action( 'delete_attachment', array( $this, 'delete_attachment_from_configs' ), 10, 1 );

		}

		/**
		 * Load Permissions for checking access to modules
		 */
		private function load_permissions() {
			if ( ! class_exists( 'PSToolkit_Permissions' ) ) {
				pstoolkit_load_single_module( 'utilities/permissions.php' );
			}
			PSToolkit_Permissions::get_instance();
		}

		/**
		 * Allow to uload SVG files.
		 *
		 * @since 1.8.9
		 */
		public function add_svg_to_allowed_mime_types( $mimes ) {
			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		}

		/**
		 * Faked instant on modules as active.
		 *
		 * @since 1.0.0
		 */
		public function add_instant_modules( $value, $option, $default ) {
			if ( ! is_array( $value ) ) {
				$value = array();
			}
			foreach ( $this->configuration as $key => $module ) {
				if ( isset( $module['instant'] ) && 'on' === $module['instant'] ) {
					$value[ $key ] = 'yes';
				}
			}
			return $value;
		}

		/**
		 * Add "PSToolkit" to admin title.
		 *
		 * @since 1.9.8
		 */
		public function admin_title( $admin_title, $title ) {
			$screen = get_current_screen();
			if ( is_a( $screen, 'WP_Screen' ) && preg_match( '/_page_branding/', $screen->id ) ) {
				$admin_title = sprintf(
					'%s%s%s',
					_x( 'CP Toolkit', 'admin title', 'ub' ),
					_x( ' &lsaquo; ', 'admin title separator', 'ub' ),
					$admin_title
				);
				if ( ! empty( $this->module ) ) {
					$module_data = $this->get_module_by_module( $this->module );
					if ( ! empty( $module_data ) && isset( $module_data['group'] ) ) {
						$groups = pstoolkit_get_groups_list();
						if ( isset( $groups[ $module_data['group'] ] ) ) {
							$admin_title = sprintf(
								'%s%s%s',
								$groups[ $module_data['group'] ]['title'],
								_x( ' &lsaquo; ', 'admin title separator', 'ub' ),
								$admin_title
							);
						}
					}
				}
			}
			return $admin_title;
		}


		/**
		 * Add message to show
		 */
		public function add_message( $message ) {
			$messages = get_user_option( $this->messages_option_name );
			if ( empty( $messages ) ) {
				$messages = array();
			}
			if ( ! in_array( $message, $messages ) ) {
				$user_id    = get_current_user_id();
				$messages[] = $message;
				update_user_option( $user_id, $this->messages_option_name, $messages, false );
			}
		}

		/**
		 * Add admin notice from option.
		 *
		 * @since 3.4
		 */
		public function add_admin_notices( $texts ) {
			$screen = get_current_screen();
			if ( ! preg_match( '/_page_branding/', $screen->id ) ) {
				return $texts;
			}
			$messages = get_user_option( $this->messages_option_name );
			if ( empty( $messages ) ) {
				return $texts;
			}
			$fire_delete = false;
			foreach ( $messages as $message ) {
				if ( ! isset( $message['message'] ) || empty( $message['message'] ) ) {
					continue;
				}
				$fire_delete              = true;
				$texts['admin_notices'][] = $message;
			}
			if ( $fire_delete ) {
				add_action( 'shutdown', array( $this, 'delete_messages' ) );
			}

			return $texts;
		}

		/**
		 * delete messages
		 *
		 * @since 3.1.0
		 */
		public function delete_messages() {
			$user_id = get_current_user_id();
			delete_user_option( $user_id, $this->messages_option_name, false );
		}

		public function setup_translation() {
			add_action( 'init', function() {
				// Load up the localization file if we're using ClassicPress in a different language
				// Place it in this plugin's "languages" folder and name it "mp-[value in wp-config].mo"
				$dir = sprintf( '/%s/languages', basename( pstoolkit_dir( '' ) ) );
				load_plugin_textdomain( 'ub', false, $dir );
			});
		}

		/**
		 * Check user permissions
		 *
		 * @return boolean
		 */
		private function check_user_access() {
			return PSToolkit_Permissions::get_instance()->current_user_has_access();
		}

		public function add_admin_header_core() {
			/**
			 * Filter allow to avoid run wp_enqueue* functions.
			 *
			 * @since 1.0.0
			 * @param boolean $add Load assets or not load - it is a question.
			 */
			$add = apply_filters( 'pstoolkit_add_admin_header_core', true );
			if ( ! $add ) {
				return;
			}

			global $wp_version;
			wp_register_script(
				'pstoolkit-sui-a11y-dialog',
				pstoolkit_url( 'external/a11y-dialog/a11y-dialog.js' ),
				array(),
				$this->build,
				true
			);
			wp_register_script(
				'pstoolkit-sui-select2',
				pstoolkit_url( 'external/select2/select2.full.js' ),
				array(),
				$this->build,
				true
			);

			/**
			 * Shared UI
			 *
			 * @since 1.0.0
			 */
			if ( defined( 'PSTOOLKIT_SUI_VERSION' ) ) {
				$sanitize_version = str_replace( '.', '-', PSTOOLKIT_SUI_VERSION );
				$sui_body_class   = "sui-$sanitize_version";
				wp_register_script(
					'sui-scripts',
					pstoolkit_url( 'assets/js/shared-ui.js' ),
					array( 'jquery', 'pstoolkit-sui-ace', 'pstoolkit-sui-a11y-dialog', 'pstoolkit-sui-select2' ),
					$sui_body_class,
					true
				);
				wp_enqueue_style(
					'sui-styles',
					pstoolkit_url( 'assets/css/shared-ui.min.css' ),
					array(),
					$sui_body_class
				);
			}
			// Add in the core CSS file
			$file = pstoolkit_url( 'assets/css/pstoolkit-admin.min.css' );
			wp_enqueue_style( 'pstoolkit-admin', $file, array(), $this->build );
			wp_enqueue_script(
				array(
					'jquery-ui-sortable',
				)
			);
			$file = sprintf( 'assets/js/pstoolkit-admin%s.js', defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min' );
			wp_enqueue_script(
				'ub_admin',
				pstoolkit_url( $file ),
				array(
					'jquery',
					'sui-scripts',
					'underscore',
					'wp-util'
				),
				$this->build,
				true
			);
			wp_enqueue_style( 'wp-color-picker' );
			$file = pstoolkit_url( 'external/wp-color-picker-alpha/wp-color-picker-alpha.min.js' );
			wp_enqueue_script( 'wp-color-picker-alpha', $file, array( 'wp-color-picker' ), '2.1.3', true );
			$color_picker_strings = array(
				'clear'            => __( 'Leeren', 'ub' ),
				'clearAriaLabel'   => __( 'Leere Farbe', 'ub' ),
				'defaultString'    => __( 'Standard', 'ub' ),
				'defaultAriaLabel' => __( 'Wähle Standardfarbe', 'ub' ),
				'pick'             => __( 'Wähle Farbe', 'ub' ),
				'defaultLabel'     => __( 'Farbwert', 'ub' ),
			);
			wp_localize_script( 'wp-color-picker-alpha', 'wpColorPickerL10n', $color_picker_strings );

			/**
			 * Messages
			 */
			$messages = array(
				'messages' => array(
					'copy'    => array(
						'confirm'      => __( 'Bist Du sicher, alle Abschnittsdaten zu ersetzen?', 'ub' ),
						'select_first' => __( 'Bitte wähle zuerst ein Quellmodul aus.', 'ub' ),
					),
					'reset'   => array(
						'module' => __( 'Bist du sicher? Dadurch werden alle eingegebenen Daten durch Standardeinstellungen ersetzt.', 'ub' ),
					),
					'welcome' => array(
						'empty' => __( 'Bitte wähle zuerst einige Module aus oder überspringe diesen Schritt.', 'ub' ),
					),
					'form'    => array(
						'number' => array(
							'max' => __( 'Der eingegebene Wert liegt über dem Feldlimit!', 'ub' ),
							'min' => __( 'Der eingegebene Wert liegt unter der Feldgrenze!', 'ub' ),
						),
					),
					'unsaved' => __( 'Änderungen werden nicht gespeichert. Möchtest Du wirklich weg navigieren?', 'ub' ),
					'feeds'   => array(
						'fetch' => esc_html__( 'Versuche, Feed-Daten abzurufen, bitte warten...', 'ub' ),
						'no'    => esc_html__( 'Kein Feed gefunden, versuche es mit einer anderen Seite oder gib die Daten manuell ein.', 'ub' ),
					),
					'export'  => array(
						'not_json' => esc_html__( 'Hoppla, nur .json-Dateitypen sind zulässig.', 'ub' ),
					),
					'common'  => array(
						'only_image' => esc_html__( 'Hoppla, nur Bilder sind erlaubt.', 'ub' ),
					),
				),
				'buttons'  => array(
					'save_changes' => __( 'Änderungen speichern', 'ub' ),
				),
			);
			foreach ( $this->messages as $key => $value ) {
				$messages['messages'][ $key ] = $value;
			}
			/**
			 * Filter messages array
			 *
			 * @since 1.0.0
			 */
			$messages = apply_filters( 'pstoolkit_admin_messages_array', $messages );
			wp_localize_script( 'ub_admin', 'ub_admin', $messages );
		}

		public function add_admin_header_branding() {
			$this->add_admin_header_core();
			do_action( 'pstoolkit_admin_header_global' );
			$update = apply_filters( 'pstoolkit_update_branding_page', true );
			if ( $update ) {
				$this->update_branding_page();
			}
		}

		/**
		 * Set module status from "Manage All Modules" page.
		 *
		 * @since 1.0.0
		 */
		public function ajax_bulk_modules() {
			$fields = array( 'pstoolkit', 'nonce' );
			foreach ( $fields as $field ) {
				if ( ! isset( $_POST[ $field ] ) ) {
					$args = array(
						'message' => $this->messages['missing'],
					);
					wp_send_json_error( $args );
				}
			}
			if (
				! wp_verify_nonce( $_POST['nonce'], 'pstoolkit-manage-all-modules' )
				&& ! wp_verify_nonce( $_POST['nonce'], 'pstoolkit-welcome-activate' )
			) {
				$args = array(
					'message' => $this->messages['security'],
				);
				wp_send_json_error( $args );
			}
			$modules = $_POST['pstoolkit'];
			if ( ! is_array( $modules ) ) {
				$modules = array();
			}
			$activated = $deactivated = 0;
			foreach ( $this->configuration as $key => $module ) {
				if ( isset( $module['instant'] ) && $module['instant'] ) {
					continue;
				}
				$is_active = pstoolkit_is_active_module( $key );
				if ( in_array( $module['module'], $modules ) ) {
					if ( ! $is_active ) {
						$this->activate_module( $key );
						$activated++;
					}
				} else {
					if ( $is_active ) {
						$this->deactivate_module( $key );
						$deactivated++;
					}
				}
			}
			$message = '';
			if ( 0 < $activated ) {
				$message .= sprintf(
					_n(
						'%d neues Modul wurde erfolgreich aktiviert.',
						'%d neue Module wurden erfolgreich aktiviert.',
						$activated,
						'ub'
					),
					number_format_i18n( $activated )
				);
				if ( 0 < $deactivated ) {
					$message .= ' ';
				}
			}
			if ( 0 < $deactivated ) {
				$message .= sprintf(
					_n(
						'%d Modul wurde erfolgreich deaktiviert.',
						'%d Module wurden erfolgreich deaktiviert.',
						$deactivated,
						'ub'
					),
					number_format_i18n( $deactivated )
				);
			}
			/**
			 * Speciall message, when was nothing to do!
			 */
			if ( 0 === $activated && 0 === $deactivated ) {
				$args             = array(
					'type'        => 'info',
					'can_dismiss' => true,
					'message'     => sprintf(
						'<q>%s</q> &mdash; <i>%s</i>',
						esc_html__( '42: Die Antwort auf das Leben, das Universum und alles.', 'ub' ),
						esc_html__( 'Douglas Adams', 'ub' )
					),
				);
				$args['message'] .= '<br >';
				$args['message'] .= __( 'Nichts wurde geändert, nichts aktiviert oder deaktiviert.', 'ub' );
				wp_send_json_error( $args );
			}
			if ( empty( $message ) ) {
				$args = array(
					'message' => $this->messages['wrong'],
				);
				wp_send_json_error( $args );
			}
			$message = array(
				'type'    => 'success',
				'message' => $message,
			);
			$this->add_message( $message );
			wp_send_json_success();
		}

		/**
		 * Check plugins those will be used if they are active or not
		 */
		public function load_modules() {
			$this->set_configuration();
			// Load our remaining modules here
			foreach ( $this->modules as $module => $plugin ) {
				if ( pstoolkit_is_active_module( $module ) ) {
					if ( ! isset( $this->configuration[ $module ] ) ) {
						continue;
					}
					if ( $this->should_be_module_off( $this->configuration[ $module ] ) ) {
						continue;
					}
					pstoolkit_load_single_module( $module );
				}
			}
			/**
			 * set related
			 *
			 * @since 2.3.0
			 */
			$this->related = apply_filters( 'pstoolkit_related_modules', $this->related );
		}

		/**
		 * add bold
		 *
		 * @since 2.1.0
		 */
		private function bold( $a ) {
			return sprintf( '"<b>%s</b>"', $a );
		}

		/**
		 * Separate logo
		 *
		 * @since 1.0.0
		 */
		public function get_u_logo() {
			$image = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNTAiIGhlaWdodD0iMTUwIj48ZyB0cmFuc2Zvcm09Im1hdHJpeCguMzAzMDMgMCAwIC0uMzAzMDMgLTc2LjUxNSAyNTAuNzYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgzNjUsNzY1KSI+PHBhdGggZD0ibTAgMHYtMjM1YzAtNTUuMDU3IDMzLjEyOS0xMDIuNTIgODAuNS0xMjMuNTF2MzU4LjUxaC04MC41em0xMzUtNDAwYy05MC45ODEgMC0xNjUgNzQuMDE5LTE2NSAxNjV2MjY1aDE0MC41di0zOTcuNzdjNy45NDgtMS40NjMgMTYuMTM2LTIuMjI4IDI0LjUtMi4yMjggNzQuNDM5IDAgMTM1IDYwLjU2MSAxMzUgMTM1djI2NWgzMHYtMjY1YzAtOTAuOTgxLTc0LjAxOS0xNjUtMTY1LTE2NSIgZmlsbD0iIzViNWM3MiIvPjwvZz48L2c+PC9zdmc+Cg==';
			return 'data:image/svg+xml;base64,' . $image;
		}

		/**
		 * Add main menu
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability.
		 */
		private function menu( $capability ) {
			$parent_menu_title = PSToolkit_Helper::is_pro() ? __( 'CP Toolkit', 'ub' ) : __( 'CP Toolkit', 'ub' );

			// Add in our menu page
			$this->top_page_slug = add_menu_page(
				$parent_menu_title,
				$parent_menu_title,
				$capability,
				'branding',
				array( $this, 'handle_main_page' ),
				$this->get_u_logo()
			);
			add_action( 'admin_init', array( $this, 'add_action_hooks' ) );
			add_action( 'load-' . $this->top_page_slug, array( $this, 'add_admin_header_branding' ) );
			$menu = add_submenu_page(
				'branding',
				__( 'Übersicht', 'ub' ),
				__( 'Übersicht', 'ub' ),
				$capability,
				'branding',
				array( $this, 'handle_main_page' )
			);
			add_action( 'load-' . $menu, array( $this, 'load_dashboard' ) );
			/**
			 * Sort sub menu items.
			 */
			uasort( $this->submenu, array( $this, 'sort_sub_menus' ) );
			/**
			 * Add groups submenus
			 */
			foreach ( $this->submenu as $key => $data ) {
				$show = true;
				if ( $this->is_network && ! is_network_admin() ) {
					$modules = $this->get_modules_by_group( $key );
					$show    = apply_filters( 'pstoolkit_group_check_for_subsite', false, $key, $modules );
				}
				if ( ! $show ) {
					continue;
				}
				$menu = add_submenu_page(
					'branding',
					$data['title'],
					$data['title'],
					$capability,
					sprintf( 'branding_group_%s', esc_attr( $key ) ),
					array( $this, 'handle_group' )
				);
				add_action( 'load-' . $menu, array( $this, 'add_admin_header_branding' ) );
			}

			/*if ( ! PSToolkit_Helper::is_member() ) {
				$menu = add_submenu_page(
					'branding',
					__( 'Psource CP Toolkit', 'ub' ),
					__( 'Psource CP Toolkit', 'ub' ),
					$capability,
					'pstoolkit_pro',
					array( $this, 'handle_pstoolkit_pro' )
				);
				add_action( 'load-' . $menu, array( $this, 'add_admin_header_branding' ) );
			}*/

			do_action( 'pstoolkit_add_menu_pages' );
		}

		/**
		 * Add pages
		 */
		public function admin_page() {
			/**
			 * Check show?
			 */
			$show = true;
			if ( $this->is_network ) {
				$show = false;
				foreach ( $this->submenu as $key => $data ) {
					if ( $show ) {
						continue;
					}
					$modules = $this->get_modules_by_group( $key );
					$show    = apply_filters( 'pstoolkit_group_check_for_subsite', false, $key, $modules );
				}
			}
			if ( $show ) {
				// Check user permissions
				if ( ! $this->check_user_access() ) {
					return;
				}
				$this->menu( 'read' );
			}
			self::$is_show_admin_menu = (bool) $show;
		}

		/**
		 * Add pages
		 */
		public function network_admin_page() {
			if ( $this->is_network && $this->check_user_access() ) {
				$this->menu( 'read' );
			}
		}

		/**
		 * Sort admin sub menus.
		 *
		 * We need to make sure the main dashboard menu
		 * gets the first priority.
		 *
		 * @param mixed $a
		 * @param mixed $b
		 *
		 * @return int
		 */
		private function sort_sub_menus( $a, $b ) {
			if ( isset( $b['menu-position'] ) && 'bottom' === $b['menu-position'] ) {
				return -1;
			}
			if ( isset( $a['menu-position'] ) && 'bottom' === $a['menu-position'] ) {
				return 1;
			}
			return strcasecmp( $a['title'], $b['title'] );
		}

		public function activate_module( $module ) {
			$update  = true;
			$modules = get_pstoolkit_activated_modules();
			if (
				isset( $modules[ $module ] )
			) {
				if ( 'yes' !== $modules[ $module ] ) {
					$update             = true;
					$modules[ $module ] = 'yes';
				}
			} else {
				$update             = true;
				$modules[ $module ] = 'yes';
			}
			if ( $update ) {
				$modules[ $module ] = 'yes';
				update_pstoolkit_activated_modules( $modules );
				pstoolkit_load_single_module( $module );
				do_action( 'pstoolkit_module_activated', $module );
				return true;
			}
			return false;
		}

		public function deactivate_module( $module ) {
			$modules = get_pstoolkit_activated_modules();
			if ( isset( $modules[ $module ] ) ) {
				unset( $modules[ $module ] );
				update_pstoolkit_activated_modules( $modules );
				do_action( 'pstoolkit_module_deactivated', $module );
				return true;
			}
			return false;
		}

		public function update_branding_page() {
			global $action, $page;
			wp_reset_vars( array( 'action', 'page' ) );
			if ( isset( $_REQUEST['action'] ) && ! empty( $_REQUEST['action'] ) ) {
				$t = PSToolkit_Helper::hyphen_to_underscore( $this->module );
				/**
				 * check
				 */
				check_admin_referer( 'pstoolkit_settings_' . $t );
				$result = apply_filters( 'pstoolkit_settings_' . $t . '_process', true );
				$url    = wp_validate_redirect( wp_get_raw_referer() );
				if ( is_array( $result ) ) {
					$url = add_query_arg( $result, $url );
				}
				wp_safe_redirect( $url );
				do_action( 'pstoolkit_settings_update_' . $t );
			}
		}

		/**
		 * Helper to build link
		 *
		 * @since 1.0.0
		 */
		private function get_module_link( $module ) {
			$url  = add_query_arg(
				array(
					'page'   => sprintf( 'branding_group_%s', $module['group'] ),
					'module' => $module['module'],
				),
				is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
			);
			$link = sprintf(
				'<a href="%s" class="pstoolkit-module pstoolkit-module-%s" data-group="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $module['module'] ),
				esc_attr( $module['group'] ),
				esc_html( $module['name'] )
			);
			return $link;
		}

		/**
		 * Helper to get array of modules state.
		 *
		 * @since 1.0.0
		 * @since 3.2.0 Added $subsite param.
		 *
		 * @param boolean $subsite Subsite mode.
		 *
		 * @return array $modules Array of modules, grupped.
		 */
		public function get_modules_stats( $subsite = false ) {
			$modules = array();
			foreach ( $this->configuration as $key => $module ) {
				if ( ! array_key_exists( $key, $this->modules ) ) {
					continue;
				}
				/**
				 * check for subsites
				 */
				if ( $subsite ) {
					$show = apply_filters( 'pstoolkit_module_check_for_subsite', false, $key, $module );
					if ( false === $show ) {
						continue;
					}
				}
				if ( ! isset( $modules[ $module['group'] ] ) ) {
					$modules[ $module['group'] ] = array();
				}
				$modules[ $module['group'] ]['modules'][ $key ]           = $module;
				$modules[ $module['group'] ]['modules'][ $key ]['status'] = 'inactive';
				if ( pstoolkit_is_active_module( $key ) ) {
					$modules[ $module['group'] ]['modules'][ $key ]['status'] = 'active';
				}
			}
			foreach ( $modules as $group => $data ) {
				$modules[ $group ]['modules'] = $data['modules'];
			}
			return $modules;
		}

		public function handle_pstoolkit_pro() {
			add_filter( 'pstoolkit_show_manage_all_modules_button', '__return_false' );
			$classes  = apply_filters( 'pstoolkit_sui_wrap_class', array(), $this->module );
			$template = 'admin/pstoolkit-pro';
			printf(
				'<main class="%s">',
				esc_attr( implode( ' ', $classes ) )
			);
			$this->render( $template );

			$this->footer();

			echo '</main>';
		}

		public function handle_main_page() {
			if ( $this->is_network && ! is_network_admin() ) {
				$this->handle_main_page_subsite();
				return;
			}
			$this->handle_main_page_global();
		}

		private function handle_main_page_global() {
			$stats              = $this->stats->get_stats();
			$recently_activated = $recently_deactivated = __( 'none', 'ub' );
			if ( isset( $stats['activites'] ) ) {
				if (
					isset( $stats['activites']['activate'] )
					&& isset( $this->configuration[ $stats['activites']['activate'] ] )
				) {
					$recently_activated = $this->get_module_link(
						$this->configuration[ $stats['activites']['activate'] ]
					);
				}
				if (
					isset( $stats['activites']['deactivate'] )
					&& isset( $this->configuration[ $stats['activites']['deactivate'] ] )
				) {
					$recently_deactivated = $this->get_module_link(
						$this->configuration[ $stats['activites']['deactivate'] ]
					);
				}
			}
			$args = array(
				'stats'                          => array(
					'active'               => 0,
					'total'                => 0,
					'recently_activated'   => $recently_activated,
					'recently_deactivated' => $recently_deactivated,
					'frequently_used'      => array(),
					'modules'              => $this->stats->get_frequently_used_modules(),
					'raw'                  => $this->stats->get_modules_raw_data(),
				),
				'show_manage_all_modules_button' => $this->show_manage_all_modules_button(),
				'helps'                          => $this->get_helps_list(),
			);
			if ( $args['stats']['modules'] ) {
				foreach ( $args['stats']['modules'] as $key => $value ) {
					if ( ! array_key_exists( $key, $this->modules ) ) {
						continue;
					}
					if ( isset( $this->configuration[ $key ] ) ) {
						$args['stats']['modules'][ $key ]           = $this->configuration[ $key ];
						$args['stats']['modules'][ $key ]['status'] = 'inactive';
						if ( pstoolkit_is_active_module( $key ) ) {
							$args['stats']['modules'][ $key ]['status'] = 'active';
						}
					} else {
						unset( $args['stats']['modules'][ $key ] );
					}
				}
			}
			/**
			 * Count
			 */
			foreach ( $this->configuration as $key => $module ) {
				if ( ! array_key_exists( $key, $this->modules ) ) {
					continue;
				}
				if ( pstoolkit_is_active_module( $key ) ) {
					if ( isset( $module['instant'] ) && $module['instant'] ) {
						continue;
					}
					$args['stats']['active']++;
				}
				$args['stats']['total']++;
			}
			/**
			 * Modules Status
			 */
			$args['modules'] = $this->get_modules_stats();
			/**
			 * groups
			 */
			$args['groups'] = pstoolkit_get_groups_list();
			/**
			 * SUI
			 */
			$args['sui']                         = array(
				'summary' => array(
					'style'   => $this->get_box_summary_image_style(),
					'classes' => array(
						'sui-box',
						'sui-summary',
					),
				),
			);
			$args['sui']['summary']['classes'][] = $this->get_hide_branding_class();
			/**
			 * render
			 */
			$classes  = apply_filters( 'pstoolkit_sui_wrap_class', array(), $this->module );
			$template = 'admin/dashboard';
			printf(
				'<main class="%s">',
				implode( ' ', $classes )
			);
			$this->render( $template, $args );
			if ( $this->show_welcome_dialog ) {
				$args     = array(
					'dialog_id' => 'pstoolkit-welcome',
					'modules'   => $args['modules'],
					'groups'    => pstoolkit_get_groups_list(),
				);
				$template = 'admin/dashboard/welcome';
				$this->render( $template, $args );
			}
			//$this->footer();
			echo '</main>';
		}

		/**
		 * Dash integration
		 *
		 * @since 3.2.0
		 * @return string Class name
		 */
		public function get_hide_branding_class() {
			$class          = '';
			$hide_branding  = apply_filters( 'psource_branding_hide_branding', $this->hide_branding );
			$branding_image = apply_filters( 'psource_branding_hero_image', null );
			if ( $hide_branding && ! empty( $branding_image ) ) {
				$class = 'sui-rebranded';
			} elseif ( $hide_branding && empty( $branding_image ) ) {
				$class = 'sui-unbranded';
			}

			return $class;
		}

		/**
		 * Handle Dashboard for subsites.
		 *
		 * @since 3.2.0
		 */
		private function handle_main_page_subsite() {
			$modules = $this->get_modules_stats( true );
			$count   = 0;
			foreach ( $modules as $group ) {
				if ( isset( $group['modules'] ) && is_array( $group['modules'] ) ) {
					$count += count( $group['modules'] );
				}
			}
			$args = array(
				'stats'                          => array(
					'active'               => $count,
					'total'                => 0,
					'recently_activated'   => '',
					'recently_deactivated' => '',
					'frequently_used'      => array(),
					'modules'              => $this->stats->get_frequently_used_modules( 'subsite' ),
					'raw'                  => $this->stats->get_modules_raw_data(),
				),
				'show_manage_all_modules_button' => $this->show_manage_all_modules_button(),
				'helps'                          => $this->get_helps_list(),
				'groups'                         => pstoolkit_get_groups_list(),
				'modules'                        => $modules,
				'message'                        => apply_filters(
					'pstoolkit_subsites_dashboard_message',
					array(
						'url'  => $this->get_network_permissions_url(),
						'show' => true,
					)
				),
			);
			if ( $args['stats']['modules'] ) {
				foreach ( $args['stats']['modules'] as $key => $value ) {
					if ( ! array_key_exists( $key, $this->modules ) ) {
						continue;
					}
					if ( isset( $this->configuration[ $key ] ) ) {
						$args['stats']['modules'][ $key ]           = $this->configuration[ $key ];
						$args['stats']['modules'][ $key ]['status'] = 'inactive';
						if ( pstoolkit_is_active_module( $key ) ) {
							$args['stats']['modules'][ $key ]['status'] = 'active';
						}
					} else {
						unset( $args['stats']['modules'][ $key ] );
					}
				}
			}
			/**
			 * render
			 */
			$classes  = apply_filters( 'pstoolkit_sui_wrap_class', array(), $this->module );
			$template = 'admin/dashboard/subsite';
			printf(
				'<main class="%s">',
				implode( ' ', $classes )
			);
			$this->render( $template, $args );
			$this->footer();
			echo '</main>';
		}

		/**
		 * Show group page
		 *
		 * @since 1.0.0
		 */
		public function handle_group() {
			$classes = array(
				sprintf( 'sui-wrap-pstoolkit-module-%s', $this->module ),
			);
			$classes = apply_filters( 'pstoolkit_sui_wrap_class', $classes, $this->module );
			printf( '<main class="%s">', implode( ' ', $classes ) );
			$content = apply_filters( 'pstoolkit_handle_group_page', '', $this->module );
			if ( ! empty( $content ) ) {
				echo $content;
			} else {
				/**
				 * Common header
				 */
				$args = array(
					'title'                          => $this->get_current_group_title(),
					'show_manage_all_modules_button' => $this->show_manage_all_modules_button(),
					'documentation_chapter'          => $this->get_current_group_documentation_chapter(),
					'helps'                          => $this->get_helps_list(),
				);
				$this->render( 'admin/common/header', $args );
				/**
				 * Content
				 */
				echo '<div class="sui-row-with-sidenav">';
				echo '<div class="sui-sidenav">';
				$this->group_tabs( 'menu' );
				echo '</div>'; // sui-sidenav
				$this->group_tabs( 'content' );
				echo '</div>'; // sui-row-with-sidenav
			}
			//$this->footer();
			echo '</main>';
		}

		/**
		 * Helper to show group
		 *
		 * @since 1.0.0
		 */
		private function group_tabs( $type ) {
			$modules = $this->get_modules_by_group( null, true );
			if ( is_wp_error( $modules ) ) {
				if ( 'content' === $type ) {
					$error_string = $modules->get_error_message();
					echo '<div class="error"><p>' . $error_string . '</p></div>';
				}
				return;
			}
			/**
			 * Get current module or set first
			 */
			$current = $modules[ key( $modules ) ]['module'];
			if ( ! empty( $this->module ) ) {
				$current = $this->module;
			}
			$content = '';
			switch ( $type ) {
				case 'menu':
					$content = $this->group_tabs_menu( $modules, $current );
					break;
				case 'content':
					$content = $this->group_tabs_content( $modules, $current );
					break;
				default:
					break;
			}
			echo $content;
		}

		private function group_tabs_menu( $modules, $current ) {
			$tabs   = '';
			$select = '';
			foreach ( $modules as $id => $module ) {
				$slug  = $module['module'];
				$title = $module['name'];
				if ( isset( $module['title'] ) ) {
					$title = $module['title'];
				}
				if ( isset( $module['menu_title'] ) ) {
					$title = $module['menu_title'];
				}
				if ( ! empty( $module['only_pro'] ) ) {
					$icon = PSToolkit_Helper::maybe_pro_tag();
				} else {
					unset( $icon );
				}
				/**
				 * Active?
				 */
				if ( empty( $icon ) ) {
					$icon = pstoolkit_is_active_module( $id ) ? '<i class="sui-icon-check-tick"></i>' : '';
				}

				if ( isset( $module['instant'] ) && 'on' === $module['instant'] ) {
					$icon = '';
				}
				$tabs   .= sprintf(
					'<li class="sui-vertical-tab %s"><a href="#" data-tab="%s">%s%s</a></li>',
					esc_attr( $current === $slug ? 'current' : '' ),
					sanitize_title( $slug ),
					esc_html( $title ),
					$icon
				);
				$select .= sprintf(
					'<option %s value="%s">%s</option>',
					esc_attr( $current === $slug ? 'selected="selected' : '' ),
					sanitize_title( $slug ),
					esc_html( $title )
				);
			}
			$content  = '<ul class="sui-vertical-tabs sui-sidenav-hide-md">';
			$content .= $tabs;
			$content .= '</ul>';
			$content .= '<div class="sui-sidenav-hide-lg">';
			$content .= '<select class="sui-mobile-nav" id="pstoolkit-mobile-nav" style="display: none;">';
			$content .= $select;
			$content .= '</select>';
			$content .= '</div>';
			return $content;
		}

		private function group_tabs_content( $modules, $current ) {
			$content               = '';
			$some_module_is_active = false;
			$show_deactivate       = true;
			if ( $this->is_network && ! $this->is_network_admin ) {
				$show_deactivate = false;
			};
			foreach ( $modules as $id => $module ) {
				$slug      = $module['module'];
				$is_active = pstoolkit_is_active_module( $module['key'] );
				/**
				 * Hide options if subsites configuration
				 */
				$has_susbsite_configuration = false;
				if ( $this->is_network && $this->is_network_admin ) {
					$subsite = apply_filters( 'pstoolkit_module_check_for_subsite', false, $id, $module );
					if ( $subsite ) {
						$has_susbsite_configuration = true;
					}
				}
				$module_name = PSToolkit_Helper::hyphen_to_underscore( $module['module'] );
				$action      = 'pstoolkit_settings_' . $module_name;
				/**
				 * Module header
				 *
				 * hide for instant active modules
				 */
				$show_module_header = true;
				if ( isset( $module['instant'] ) && 'on' === $module['instant'] ) {
					$show_module_header = false;
				}
				if ( $show_module_header ) {
					$classes = array(
						'sui-box',
						'pstoolkit-settings-tab',
						sprintf( 'pstoolkit-settings-tab-%s', sanitize_title( $slug ) ),
						sprintf( 'pstoolkit-settings-tab-title-%s', sanitize_title( $slug ) ),
						'pstoolkit-settings-tab-title',
					);
					$buttons = '';
					if ( $is_active ) {
						$template  = 'admin/common/modules/header';
						$classes[] = 'sui-box-sticky';
						/**
						 * deactivate button
						 */
						if (
							$show_deactivate
							&& (
								! isset( $module['instant'] ) || 'on' !== $module['instant']
							)
						) {
							$args     = array(
								'data'  => array(
									'nonce' => wp_create_nonce( $slug ),
									'slug'  => $slug,
								),
								'class' => 'ub-deactivate-module',
								'text'  => __( 'Deaktivieren', 'ub' ),
								'sui'   => 'ghost',
							);
							$buttons .= $this->button( $args );
						}
						/**
						 * submit button
						 */
						$filter = $action . '_process';
						if (
							has_filter( $filter )
							&& apply_filters( 'pstoolkit_settings_panel_show_submit', true, $module )
						) {
							$args     = array(
								'text'  => __( 'Änderungen speichern', 'ub' ),
								'sui'   => 'blue',
								'icon'  => 'save',
								'class' => 'pstoolkit-module-save',
							);
							$buttons .= $this->button( $args );
						}
					} else {
						$template = '/admin/modules/' . $module['module'] . '/module-inactive';
						if ( ! self::get_template_file_name( $template ) ) {
							// If module custom template doesn't exist - use common one.
							$template = 'admin/common/module-inactive';
						}
						/**
						 * activate button
						 */
						$args    = array(
							'data'  => array(
								'nonce' => wp_create_nonce( $slug ),
								'slug'  => $slug,
							),
							'class' => 'ub-activate-module',
							'sui'   => 'blue',
							'text'  => __( 'Aktivieren', 'ub' ),
						);
						$buttons = $this->button( $args );
					}
					$status_indicator = isset( $module['status-indicator'] ) ? $module['status-indicator'] : 'show';
					$args             = array(
						'box_title'                  => isset( $module['name_alt'] ) ? $module['name_alt'] : $module['name'],
						'classes'                    => $classes,
						'module'                     => $module,
						'copy_button'                => $this->get_copy_button( $module ),
						'buttons'                    => $buttons,
						'slug'                       => $slug,
						'current'                    => $current,
						'status_indicator'           => $status_indicator,
						'has_susbsite_configuration' => $has_susbsite_configuration,
					);
					$content         .= $this->render( $template, $args, true );
				}
				/**
				 * body
				 */
				if ( $is_active ) {
					$classes  = array(
						'sui-box',
						'pstoolkit-settings-tab',
						sprintf( 'pstoolkit-settings-tab-%s', sanitize_title( $slug ) ),
						'pstoolkit-settings-tab-content',
						sprintf( 'pstoolkit-settings-tab-content-%s', sanitize_title( $slug ) ),
					);
					$classes  = apply_filters( 'pstoolkit_settings_tab_content_classes', $classes, $module );
					$content .= sprintf(
						'<div class="%s" data-tab="%s"%s>',
						esc_attr( implode( ' ', $classes ) ),
						esc_attr( sanitize_title( $slug ) ),
						$current === $slug ? '' : ' style="display: none;"'
					);
					/**
					 * Show module content
					 */
					$show_module_content = true;
					if ( $has_susbsite_configuration ) {
						$show_module_content = false;
					}
					if ( ! $show_module_content ) {
						$show_message = true;
						if (
							isset( $module['allow-override-message'] )
							&& 'hide' === $module['allow-override-message']
						) {
							$show_message = false;
						}
						if ( $show_message ) {
							$template = 'admin/common/modules/subsite-configuration';
							$args     = array(
								'url' => $this->get_network_permissions_url(),
							);
							$content .= $this->render( $template, $args, true );
						}
					}
					$module_content = $this->get_module_content( $module );
					if ( is_wp_error( $module_content ) ) {
						$content .= '<div class="sui-box-body">';
						$content .= PSToolkit_Helper::sui_notice( $module_content->get_error_message() );
						$content .= '</div>'; // sui-box-body
					} else {
						$content .= $module_content;
					}
					$content .= '</div>'; // sui-box
				}
				if ( $current === $slug ) {
					$some_module_is_active = true;
				}
			}
			if ( ! $some_module_is_active ) {
				$template = 'admin/common/no-module';
				$content .= $this->render( $template, array(), true );
			}
			return "<div>{$content}</div>";
		}

		/**
		 * Show New feature dialog if it's available to show
		 *
		 * @return null
		 */
		/*private function maybe_show_new_feature_dialog() {
			if ( ! PSToolkit_Helper::is_full_pro() ) {
				return;
			}

			$major_minor_version = $this->get_major_minor_version();
			if ( $this->to_major_minor( $this->get_first_installed_version() ) === $major_minor_version ) {
				// Only need to show after an upgrade, not fresh installation
				return;
			}

			$meta_key = 'pstoolkit_hide_new_features';

			$dismissed_dialog_version = get_user_meta( get_current_user_id(), $meta_key, true );

			if ( version_compare( $major_minor_version, $dismissed_dialog_version, '<=' ) ) {
				return;
			}

			$template_suffix = str_replace( '.', '', $major_minor_version );
			$template        = 'admin/common/dialogs/show-new-features-' . $template_suffix;
			$this->render( $template, array() );
		}*/

		/**
		 * Add notice template and footer "In love by WPMU DEV".
		 *
		 * @since 1.0.0
		 */
		private function footer() {
			$show = $this->show_manage_all_modules_button();
			if ( $show ) {
				/**
				 * Modules Status & Manage All Modules
				 */
				$args     = array(
					'modules' => $this->get_modules_stats(),
					'groups'  => pstoolkit_get_groups_list(),
				);
				$template = 'admin/common/dialogs/manage-all-modules';
				$this->render( $template, $args );
			}

			//$this->maybe_show_new_feature_dialog();

			/*$hide_footer = true;
			$footer_text = sprintf( __( 'Made with %s by WPMU DEV', 'ub' ), ' <i class="sui-icon-heart"></i>' );
			if ( PSToolkit_Helper::is_member() ) {
				$hide_footer = apply_filters( 'psource_branding_change_footer', $hide_footer );
				$footer_text = apply_filters( 'psource_branding_footer_text', $footer_text );
				$hide_footer = apply_filters( 'pstoolkit_change_footer', $hide_footer, $this->module );
				$footer_text = apply_filters( 'pstoolkit_footer_text', $footer_text, $this->module );
			}
			$args     = array(
				'hide_footer' => $hide_footer,
				'footer_text' => $footer_text,
			);
			$template = 'admin/common/footer';
			$this->render( $template, $args );
			do_action( 'pstoolkit_ubadmin_footer', $this->module );*/
		}

		/**
		 * Print button save.
		 *
		 * @since 1.8.4
		 * @since 1.0.0 returns value instead of print.
		 */
		public function button_save() {
			$content = sprintf(
				'<p class="submit"><input type="submit" name="submit" class="button-primary" value="%s" /></p>',
				esc_attr__( 'Änderungen speichern', 'ub' )
			);
			return $content;
		}

		/**
		 * Should I show menu in admin subsites?
		 *
		 * @since 1.8.6
		 */
		private function check_show_in_subsites() {
			if ( is_multisite() && is_network_admin() ) {
				return true;
			}
			$modules = get_pstoolkit_activated_modules();
			if ( empty( $modules ) ) {
				return false;
			}
			foreach ( $modules as $module => $state ) {
				if ( 'yes' != $state ) {
					continue;
				}
				if ( isset( $this->configuration[ $module ] ) ) {
					$state = apply_filters( 'pstoolkit_module_check_for_subsite', false, $module, $this->configuration[ $module ] );
					if ( $state ) {
						return $state;
					}
				}
			}
			return false;
		}

		/**
		 * Get module by group.
		 *
		 * @since 1.0.0
		 * @since 3.1.0
		 *
		 * @param string $group group.
		 */
		public function get_modules_by_group( $group = null, $filter = false ) {
			global $pstoolkit_network;
			if ( null === $group ) {
				$group = $this->group;
			}
			$modules = array();
			foreach ( $this->configuration as $key => $module ) {
				if ( ! array_key_exists( $key, $this->modules ) ) {
					continue;
				}
				if ( ! isset( $module['group'] ) ) {
					continue;
				}
				if ( $group == $module['group'] ) {
					$modules[ $key ] = $module;
				}
			}
			/**
			 * Filter
			 */
			if ( $pstoolkit_network && $filter ) {
				$is_network_admin = is_network_admin();
				if ( ! $is_network_admin ) {
					$m = array();
					foreach ( $modules as $key => $module ) {
						$show = apply_filters( 'pstoolkit_module_check_for_subsite', false, $key, $module );
						if ( $show ) {
							$m[ $key ] = $module;
						}
					}
					$modules = $m;
				}
			}
			if ( empty( $modules ) ) {
				return new WP_Error( 'error', __( 'Es gibt keine Module in der ausgewählten Gruppe!', 'ub' ) );
			}

			return $modules;
		}

		/**
		 * get nonced url
		 *
		 * @since 1.8.8
		 */
		private function get_nonce_url( $module ) {
			$page         = $this->get_current_page();
			$is_active    = pstoolkit_is_active_module( $module );
			$url          = add_query_arg(
				array(
					'page'   => $page,
					'action' => $is_active ? 'disable' : 'enable',
					'module' => $module,
				),
				is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
			);
			$nonce_action = sprintf( '%s-module-%s', $is_active ? 'disable' : 'enable', $module );
			$url          = wp_nonce_url( $url, $nonce_action );
			return $url;
		}

		/**
		 * Get base url
		 *
		 * @since 1.8.8
		 */
		private function get_base_url() {
			if ( empty( $this->base_url ) ) {
				$page           = $this->get_current_page();
				$this->base_url = add_query_arg(
					array(
						'page' => $page,
					),
					is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
				);
			}
			return $this->base_url;
		}

		/**
		 * sanitize variables
		 *
		 * @since 1.0.0
		 */
		public function set_and_sanitize_variables() {
			$this->load_permissions();

			$this->module = '';
			if (
				isset( $_REQUEST['page'] )
				&& preg_match( '/branding_group_(.+)$/', $_REQUEST['page'], $matches )
			) {
				if ( array_key_exists( $matches[1], $this->submenu ) ) {
					$this->group = $matches[1];
				}
			}
			if ( 'dashboard' === $this->group ) {
				return;
			}
			/**
			 * module
			 */
			$input_module = filter_input(INPUT_POST, 'module', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			if (empty($input_module)) {
				$input_module = filter_input(INPUT_GET, 'module', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
			}
			$is_empty = empty($input_module);
			if (!$is_empty) {
				if ('dashboard' !== $input_module) {
					foreach ($this->configuration as $module) {
						if (isset($module['module']) && $input_module === $module['module']) {
							$this->module = $module['module'];
							return;
						}
					}
				}
			}
			/**
			 * module is not requested!
			 */
			$modules = $this->get_modules_by_group( null, true );
			if ( is_wp_error( $modules ) ) {
				return;
			}
			/**
			 * try to find active one first
			 */
			$mods = $modules;
			while ( empty( $this->module ) && $module = array_shift( $mods ) ) {
				$is_active = pstoolkit_is_active_module( $module['key'] );
				if ( $is_active ) {
					$this->module = $module['module'];
				}
			}
			/**
			 * Set first module as current module.
			 */
			if ( empty( $this->module ) && is_array( $modules ) && ! empty( $modules ) ) {
				$module_data  = array_shift( $modules );
				$this->module = $module_data['module'];
			}
		}

		/**
		 * get group
		 *
		 * @since x.x.x
		 */
		public function get_current_group() {
			return $this->group;
		}

		/**
		 * Dismiss New Feature dialogs.
		 */
		public function new_feature_dismiss() {
			$dialog_id = filter_input( INPUT_POST, 'id' );
			$nonce     = filter_input( INPUT_POST, '_ajax_nonce' );
			if ( ! ( $nonce && $dialog_id ) ) {
				wp_send_json_error( array( 'message' => $this->messages['wrong'] ) );
			}

			check_ajax_referer( 'new-feature' );
			$user_id  = get_current_user_id();
			$meta_key = 'pstoolkit_hide_new_features';

			update_user_meta( $user_id, $meta_key, $this->get_major_minor_version() );
		}

		private function get_major_minor_version() {
			return $this->to_major_minor( $this->build );
		}

		private function to_major_minor( $version ) {
			if ( substr_count( $version, '.' ) > 1 ) {
				list( $major, $minor, $patch ) = explode( '.', $version );
				return "{$major}.{$minor}";
			}

			return $version;
		}

		/**
		 * Activate/deactivate single module AJAX action.
		 *
		 * @since 1.9.6
		 */
		public function toggle_module() {
			if (
				isset( $_POST['nonce'] )
				&& isset( $_POST['state'] )
				&& isset( $_POST['module'] )
			) {
				/**
				 * get module
				 */
				$module_data = $this->get_module_by_module( sanitize_key( $_POST['module'] ) );
				if ( is_wp_error( $module_data ) ) {
					$message = array(
						'message' => $module_data->get_error_message(),
					);
					wp_send_json_error( $message );
				}
				if ( ! wp_verify_nonce( $_POST['nonce'], $module_data['module'] ) ) {
					wp_send_json_error( array( 'message' => __( 'Nee! Sicherheitsüberprüfung fehlgeschlagen!', 'ub' ) ) );
				}
				$result  = false;
				$message = array(
					'message' => $this->messages['fail'],
				);
				/**
				 * try to activate or deactivate
				 */
				if ( 'on' == $_POST['state'] ) {
					$result = $this->activate_module( $module_data['key'] );
					if ( $result ) {
						$message = array(
							'type'    => 'success',
							'message' => sprintf(
								__( '%s Modul ist jetzt aktiv.', 'ub' ),
								$this->bold( $module_data['name'] )
							),
						);
					}
				} else {
					$result = $this->deactivate_module( $module_data['key'] );
					if ( $result ) {
						$message = array(
							'type'    => 'success',
							'message' => sprintf(
								__( 'Modul %s wurde fehlerfrei deaktiviert.', 'ub' ),
								$this->bold( $module_data['name'] )
							),
						);
					}
				}
				$this->add_message( $message );
				$data = array(
					'state'  => $result,
					'module' => sanitize_key( $_POST['module'] ),
				);
				wp_send_json_success( $data );
			}
			wp_send_json_error( array( 'message' => $this->messages['wrong'] ) );
		}

		private function get_module_content( $module ) {
			$is_active = pstoolkit_is_active_module( $module['key'] );
			if ( ! $is_active ) {
				return new WP_Error( 'error', __( 'Dieses Modul ist nicht aktiv!', 'ub' ) );
			}
			/**
			 * Turn off Smush scripts
			 *
			 * @since 1.0.0
			 */
			add_filter( 'wp_smush_enqueue', '__return_false' );
			$content = '';
			/**
			 * Form encoding type
			 */
			$enctype = apply_filters( 'pstoolkit_settings_form_enctype', 'multipart/form-data' );
			if ( ! empty( $enctype ) ) {
				$enctype = sprintf(
					' enctype="%s"',
					esc_attr( $enctype )
				);
			}
			/**
			 * Fields with form
			 */
			$action   = PSToolkit_Helper::hyphen_to_underscore( 'pstoolkit_settings_' . $module['module'] );
			$messages = apply_filters( $action . '_messages', $this->messages );
			if ( has_filter( $action ) ) {
				$content .= apply_filters( 'pstoolkit_before_module_form', '', $module );
				/**
				 * Filter PSToolkit form classes.
				 *
				 * @since 1.0.0
				 *
				 * @param $classes array Array of PSToolkit form classes.
				 * @param $module array Current module data,
				 */
				$classes  = apply_filters(
					'pstoolkit_form_classes',
					array(
						'pstoolkit-form',
						sprintf( 'module-%s', sanitize_title( $module['key'] ) ),
						$this->is_network ? 'pstoolkit-network' : 'pstoolkit-single',
					),
					$module
				);
				$content .= sprintf(
					'<form action="%s" method="%s" class="module-%s"%s>',
					remove_query_arg( array( 'module' ) ),
					apply_filters( 'pstoolkit_settings_form_method', 'post' ),
					esc_attr( implode( ' ', $classes ) ),
					$enctype
				);
				$content .= $this->hidden( 'module', $module['module'] );
				$content .= $this->hidden( 'page', $this->get_current_page() );
				if ( apply_filters( 'pstoolkit_settings_form_add_fields', true ) ) {
					$content .= $this->hidden( 'action', 'process' );
					/**
					 * nonce
					 */
					$content .= wp_nonce_field( $action, '_wpnonce', false, false );
				}
				$content .= apply_filters( $action, '' );
				/**
				 * footer
				 */
				if ( isset( $module['add-bottom-save-button'] ) && $module['add-bottom-save-button'] ) {
					$filter = $action . '_process';
					if (
						has_filter( $filter )
						&& apply_filters( 'pstoolkit_settings_panel_show_submit', true, $module )
					) {
						$content .= '<div class="sui-box-footer">';
						$content .= '<div class="sui-actions-right">';
						$args     = array(
							'text'  => __( 'Änderungen speichern', 'ub' ),
							'sui'   => 'blue',
							'icon'  => 'save',
							'class' => 'pstoolkit-module-save',
						);
						$args     = apply_filters( 'pstoolkit_after_form_save_button_args', $args, $module );
						$content .= $this->button( $args );
						$content .= '</div>'; // sui-actions-right
						$content .= '</div>'; // sui-box-header
					}
				}
				$content .= '</form>';
				do_action( 'pstoolkit_after_module_form', $module );
			} else {
				$content .= PSToolkit_Helper::sui_notice( $this->messages['wrong'] );
				if ( PSToolkit_Helper::is_debug() ) {
					error_log( 'Missing action: ' . $action );
				}
			}
			/**
			 * filter module content.
			 *
			 * @since 1.0.0
			 *
			 * @param string $content Current module content.
			 * @param array $module Current module.
			 */
			return apply_filters( 'pstoolkit_get_module_content', $content, $module );
		}

		/**
		 * SUI button
		 */
		public function button( $args ) {
			$content        = $data = '';
			$add_sui_loader = true;
			/**
			 * add data attributes
			 */
			if ( isset( $args['data'] ) ) {
				foreach ( $args['data'] as $key => $value ) {
					$data .= sprintf(
						' data-%s="%s"',
						sanitize_title( $key ),
						esc_attr( $value )
					);
					if ( 'modal-open' === $key ) {
						$data .= ' data-modal-mask="true"';
					}
				}
			}
			/**
			 * add disabled attribute
			 */
			if ( isset( $args['disabled'] ) && $args['disabled'] ) {
				$data .= ' disabled="disabled"';
			}
			/**
			 * add ID attribute
			 */
			if ( isset( $args['id'] ) ) {
				$data .= sprintf( ' id="%s"', esc_attr( $args['id'] ) );
			}
			/**
			 * add style attribute
			 */
			if ( isset( $args['style'] ) ) {
				$data .= sprintf( ' style="%s"', esc_attr( $args['style'] ) );
			}
			/**
			 * Build classes
			 */
			$classes = array(
				'sui-button',
			);
			if ( isset( $args['only-icon'] ) && true === $args['only-icon'] ) {
				$classes        = array();
				$add_sui_loader = false;
			}
			if ( isset( $args['sui'] ) ) {
				if ( ! empty( $args['sui'] ) ) {
					if ( ! is_array( $args['sui'] ) ) {
						$args['sui'] = array( $args['sui'] );
					}
					foreach ( $args['sui'] as $sui ) {
						$classes[] = sprintf( 'sui-button-%s', $sui );
					}
				} elseif ( false !== $args['sui'] ) {
					$classes[] = 'sui-button-blue';
				}
			}
			if ( ! isset( $args['text'] ) ) {
				$classes[] = 'sui-button-icon';
			}
			if ( isset( $args['class'] ) ) {
				$classes[] = $args['class'];
			}
			if ( isset( $args['classes'] ) && is_array( $args['classes'] ) ) {
				$classes = array_merge( $classes, $args['classes'] );
			}
			/**
			 * Start
			 */
			$content .= sprintf(
				'<button class="%s" %s type="%s">',
				esc_attr( implode( ' ', $classes ) ),
				$data,
				isset( $args['type'] ) ? esc_attr( $args['type'] ) : 'button'
			);
			if ( $add_sui_loader ) {
				$content .= '<span class="sui-loading-text">';
			}
			/**
			 * Icon
			 */
			if ( isset( $args['icon'] ) ) {
				$content .= sprintf(
					'<i class="sui-icon-%s"></i>',
					sanitize_title( $args['icon'] )
				);
			}
			if ( isset( $args['text'] ) ) {
				$content .= esc_attr( $args['text'] );
			} elseif ( isset( $args['value'] ) ) {
				$content .= esc_attr( $args['value'] );
			}
			if ( $add_sui_loader ) {
				$content .= '</span>';
				$content .= '<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>';
			}
			$content .= '</button>';
			/**
			 * Wrap
			 */
			if ( isset( $args['wrap'] ) && is_string( $args['wrap'] ) ) {
				$content = sprintf(
					'<div class="%s">%s</div>',
					esc_attr( $args['wrap'] ),
					$content
				);
			}
			return $content;
		}

		/**
		 * Helper for hidden field.
		 *
		 * @since 1.0.0
		 *
		 * @input string $name HTML form field name.
		 * @input string $value HTML form field value.
		 *
		 * @return string HTML hidden syntax.
		 */
		private function hidden( $name, $value ) {
			return sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}

		/**
		 * Remove default footer on PSToolkit screens.
		 *
		 * @since 1.0.0
		 */
		public function remove_default_footer( $content ) {
			$screen = get_current_screen();
			if (
				is_a( $screen, 'WP_Screen' )
				&& preg_match( '/_page_branding/', $screen->id )
			) {
				remove_filter( 'update_footer', 'core_update_footer' );
				return '';
			}
			return $content;
		}

		/**
		 * Get current module
		 *
		 * @since 1.0.0
		 *
		 * @return string Current module.
		 */
		public function get_current_module() {
			return $this->module;
		}

		/**
		 * Check is current module?
		 *
		 * @since 1.0.0
		 */
		public function is_current_module( $module ) {
			return $this->module === $module;
		}

		/**
		 * reset whole module
		 *
		 * @since 1.0.0
		 */
		public function ajax_reset_module() {
			if (
				isset( $_POST['_wpnonce'] )
				&& isset( $_POST['module'] )
			) {
				/**
				 * get module
				 */
				$module_data = $this->get_module_by_module( sanitize_key( $_POST['module'] ) );
				if ( is_wp_error( $module_data ) ) {
					$message = array(
						'message' => $module_data->get_error_message(),
					);
					wp_send_json_error( $message );
				}
				if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'reset-module-' . $module_data['module'] ) ) {
					wp_send_json_error( array( 'message' => $this->messages['security'] ) );
				}
				$filter = sprintf( 'pstoolkit_settings_%s_reset', $module_data['module'] );
				$status = apply_filters( $filter, false );
				if ( $status ) {
					$message = array(
						'type'    => 'success',
						'message' => sprintf(
							__( '%s Modul wurde zurückgesetzt.', 'ub' ),
							$this->bold( $module_data['name'] )
						),
					);
					$this->add_message( $message );
					wp_send_json_success();
				}
			}
			wp_send_json_error( array( 'message' => $this->messages['wrong'] ) );
		}

		/**
		 * Return messages
		 *
		 * @since 1.0.0
		 */
		public function get_messages() {
			return $this->messages;
		}

		/**
		 * Map of old -> new modules.
		 *
		 * @since 1.0.0
		 */
		private function get_modules_map() {
			$map = array(
				/**
				 * Dashboard Widgets
				 */
				'dashboard-text-widgets/dashboard-text-widgets.php' => 'widgets/dashboard-widgets.php',
				'custom-dashboard-welcome.php'            => 'widgets/dashboard-widgets.php',
				'remove-wp-dashboard-widgets.php'         => 'widgets/dashboard-widgets.php',
				'remove-wp-dashboard-widgets/remove-wp-dashboard-widgets.php' => 'widgets/dashboard-widgets.php',
				'dashboard-widgets/dashboard-widgets.php' => 'widgets/dashboard-widgets.php',
				'dashboard-feeds/dashboard-feeds.php'     => 'widgets/dashboard-feeds.php',
				/**
				 * Turn on Content Header
				 */
				'global-header-content.php'               => 'content/header.php',
				/**
				 * Turn on Content Footer
				 */
				'global-footer-content.php'               => 'content/footer.php',
				/**
				 * Turn on Email Header
				 */
				'custom-email-from.php'                   => 'emails/headers.php',
				/**
				 * Turn on Registration Emails
				 */
				'custom-ms-register-emails.php'           => 'emails/registration.php',
				/**
				 * Text Replacement ( Text Change )
				 */
				'site-wide-text-change.php'               => 'utilities/text-replacement.php',
				'text-replacement/text-replacement.php'   => 'utilities/text-replacement.php',
				/**
				 * Images: Favicons
				 * Images: Image upload size
				 */
				'favicons.php'                            => 'utilities/images.php',
				'image-upload-size.php'                   => 'utilities/images.php',
				/**
				 * Admin Bar
				 * Admin Bar Logo
				 */
				'custom-admin-bar.php'                    => 'admin/bar.php',
				'admin-bar-logo.php'                      => 'admin/bar.php',
				/**
				 * Login Screen
				 */
				'custom-login-screen.php'                 => 'login-screen/login-screen.php',
				/**
				 * Site Generator
				 */
				'site-generator-replacement.php'          => 'utilities/site-generator.php',
				/**
				 * Email Temlate
				 */
				'htmlemail.php'                           => 'emails/template.php',
				/**
				 * Blog creation: signup code
				 */
				'signup-code.php'                         => 'login-screen/signup-code.php',
				/**
				 * Color Schemes
				 */
				'ultimate-color-schemes.php'              => 'admin/color-schemes.php',
				/**
				 * Admin Footer Text
				 */
				'admin-footer-text.php'                   => 'admin/footer.php',
				/**
				 * Meta Widget
				 */
				'rebranded-meta-widget.php'               => 'widgets/meta-widget.php',
				/**
				 * Admin Custom CSS
				 */
				'custom-admin-css.php'                    => 'admin/custom-css.php',
				/**
				 * Admin Message
				 */
				'admin-message.php'                       => 'admin/message.php',
				/**
				 * Comments Control
				 */
				'comments-control.php'                    => 'utilities/comments-control.php',
				/**
				 * Blog Description on Blog Creation
				 */
				'signup-blog-description.php'             => 'front-end/signup-blog-description.php',
				/**
				 * Document
				 */
				'document.php'                            => 'front-end/document.php',
				/**
				 * Admin Help Content
				 */
				'admin-help-content.php'                  => 'admin/help-content.php',
				/**
				 * ms-site-check
				 */
				'ms-site-check/ms-site-check.php'         => 'front-end/site-status-page.php',
				/**
				 * Cookie Notice
				 */
				'cookie-notice/cookie-notice.php'         => 'front-end/cookie-notice.php',
				/**
				 * DB Error Page
				 */
				'db-error-page/db-error-page.php'         => 'front-end/db-error-page.php',
				/**
				 * Author Box
				 */
				'author-box/author-box.php'               => 'front-end/author-box.php',
				/**
				 * SMTP
				 */
				'smtp/smtp.php'                           => 'emails/smtp.php',
				/**
				 * Tracking Codes
				 */
				'tracking-codes/tracking-codes.php'       => 'utilities/tracking-codes.php',
				/**
				 * Website Mode
				 */
				'maintenance/maintenance.php'             => 'utilities/maintenance.php',
			);
			return $map;
		}

		private function get_first_installed_version() {
			return pstoolkit_get_option( 'pstoolkit_first_installed_version', '0' );
		}

		private function set_first_installed_version() {
			pstoolkit_update_option( 'pstoolkit_first_installed_version', $this->build );
		}

		/**
		 * Upgrade 
		 *
		 * @since 1.0.0
		 */
		public function upgrade() {
			$key        = 'pstoolkit_db_version';
			$db_version = intval( pstoolkit_get_option( $key, 0 ) );

			if ( empty( $db_version ) ) {
				$this->set_first_installed_version();
			}

			/**
			 * PSToolkit 2.0.0
			 */
			$value = 20190205;
			if ( $value > $db_version ) {
				$modules = get_pstoolkit_activated_modules();
				$map     = $this->get_modules_map();
				foreach ( $map as $old => $new ) {
					if (
						isset( $modules[ $old ] )
						&& 'yes' === $modules[ $old ]
					) {
						$this->deactivate_module( $old );
						$this->activate_module( $new );
					}
				}
				/**
				 * Turn on Registration Emails
				 */
				$module = 'export-import.php';
				if (
					isset( $modules[ $module ] )
					&& 'yes' === $modules[ $module ]
				) {
					$this->activate_module( 'utilities/import.php' );
					$this->activate_module( 'utilities/export.php' );
					$this->deactivate_module( $module );
				}
				/**
				 * Turn on Admin Menu
				 *
				 * Urgent: do not turn off previous modules!
				 */
				$m = array(
					'admin-panel-tips/admin-panel-tips.php',
					'link-manager.php',
					'remove-dashboard-link-for-users-without-site.php',
					'remove-permalinks-menu-item.php',
				);
				foreach ( $m as $module ) {
					if (
						isset( $modules[ $module ] )
						&& 'yes' === $modules[ $module ]
					) {
						$this->activate_module( 'admin/menu.php' );
					}
				}
				/**
				 * update
				 */
				pstoolkit_update_option( $key, $value );
			}
		}

		/**
		 * Add admin body classes
		 *
		 * @since 1.0.0
		 */
		public function add_pstoolkit_admin_body_class( $classes ) {
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if (
					preg_match( '/page_pstoolkit/', $screen->id )
					|| preg_match( '/page_branding/', $screen->id )
				) {
					if ( ! is_string( $classes ) ) {
						$classes = '';
					}
					$classes .= ' pstoolkit-admin-page';
					/**
					 * Shared UI
					 * Include library version as class on body.
					 *
					 * @since 1.0.0
					 */
					if ( defined( 'PSTOOLKIT_SUI_VERSION' ) ) {
						$sanitize_version = str_replace( '.', '-', PSTOOLKIT_SUI_VERSION );
						$classes         .= sprintf( ' sui-%s', $sanitize_version );
					}
					/**
					 * add import class
					 */
					if ( 'import' === $this->module ) {
						if (
							isset( $_REQUEST['key'] )
							&& 'error' === $_REQUEST['key']
						) {
							$classes .= ' pstoolkit-import';
						}
						if (
							isset( $_REQUEST['step'] )
							&& 'import' === $_REQUEST['step']
						) {
							$classes .= ' pstoolkit-import';
						}
					}
				}
			}
			return $classes;
		}

		/**
		 * Get configuration
		 *
		 * @since 1.0.0
		 */
		public function get_configuration() {
			return $this->configuration;
		}

		/**
		 * Get modules
		 *
		 * @since 1.0.0
		 */
		public function get_modules() {
			return $this->modules;
		}

		/**
		 * Set last "write" module usage
		 *
		 * @since 1.0.0
		 */
		public function set_last_write( $module ) {
			$module = $this->get_module_by_module( $module );
			$this->stats->set_last_write( $module['key'] );
		}

		/**
		 * Copy settings from another modules.
		 *
		 * @since 1.0.0
		 */
		private function get_copy_button( $module ) {
			$content = '';
			if ( empty( $this->related ) || ! is_array( $this->related ) ) {
				return $content;
			}
			$module_module = $module['module'];
			$related       = array();
			foreach ( $this->related as $section => $data ) {
				if ( array_key_exists( $module_module, $data ) ) {
					unset( $data[ $module_module ] );
					if ( empty( $data ) ) {
						continue;
					}
					$related[ $section ] = $data;
				}
			}
			if ( empty( $related ) ) {
				return $content;
			}
			$trans = array(
				'background'            => __( 'Hintergrund', 'ub' ),
				'logo'                  => __( 'Logo', 'ub' ),
				'social_media_settings' => __( 'Social Media-Einstellungen', 'ub' ),
				'social_media'          => __( 'Social Media', 'ub' ),
			);
			$c     = array();
			foreach ( $related as $section => $section_data ) {
				foreach ( $section_data as $module_key => $module_key_data ) {
					if ( ! isset( $c[ $module_key ] ) ) {
						$c[ $module_key ]            = $module_key_data;
						$c[ $module_key ]['options'] = array();
					}
					$c[ $module_key ]['options'][ $section ] = $trans[ $section ];
				}
			}
			$args     = array(
				'related' => $c,
				'module'  => $module,
			);
			$template = 'admin/common/copy';
			$content .= $this->render( $template, $args, true );
			return $content;
		}

		/**
		 * Copy settings from source to target module.
		 *
		 * @since 1.0.0
		 */
		public function ajax_copy_settings() {
			$target = filter_input( INPUT_POST, 'target_module', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$source = filter_input( INPUT_POST, 'source_module', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$nonce  = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if (
				empty( $target )
				|| empty( $source )
				|| empty( $nonce )
				|| ! isset( $_POST['sections'] )
			) {
				wp_send_json_error( array( 'message' => $this->messages['missing'] ) );
			}
			$nonce_action = sprintf( 'pstoolkit-copy-settings-%s', $target );
			if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				wp_send_json_error( array( 'message' => $this->messages['security'] ) );
			}
			$source_module_data          = $this->get_module_by_module( $source );
			$target_module_data          = $this->get_module_by_module( $target );
			$source_module_configuration = pstoolkit_get_option( $source_module_data['options'][0] );
			$target_module_configuration = pstoolkit_get_option( $target_module_data['options'][0], array() );
			if ( empty( $source_module_configuration ) ) {
				wp_send_json_error( array( 'message' => __( 'Bitte konfiguriere zuerst das Quellmodul!', 'ub' ) ) );
			}
			/**
			 *
			 */
			if ( ! is_array( $target_module_configuration ) ) {
				$target_module_configuration = array();
			}
			$copy = array(
				'content' => array(),
				'design'  => array(),
				'colors'  => array(),
			);
			foreach ( $_POST['sections'] as $section ) {
				switch ( $section ) {
					case 'background':
						$copy['content'][] = 'content_background';
						$copy['design'][]  = 'background_mode';
						$copy['design'][]  = 'background_duration';
						$copy['design'][]  = 'background_size';
						$copy['design'][]  = 'background_size_width';
						$copy['design'][]  = 'background_size_height';
						$copy['design'][]  = 'background_focal';
						$copy['design'][]  = 'background_crop';
						$copy['design'][]  = 'background_crop_width';
						$copy['design'][]  = 'background_crop_height';
						$copy['design'][]  = 'background_crop_width_p';
						$copy['design'][]  = 'background_crop_height_p';
						$copy['design'][]  = 'background_attachment';
						$copy['design'][]  = 'background_size';
						$copy['design'][]  = 'background_size_width';
						$copy['design'][]  = 'background_size_height';
						$copy['design'][]  = 'background_position_x';
						$copy['design'][]  = 'background_position_x_custom';
						$copy['design'][]  = 'background_position_x_units';
						$copy['design'][]  = 'background_position_y';
						$copy['design'][]  = 'background_position_y_custom';
						$copy['design'][]  = 'background_position_y_units';
						$copy['colors'][]  = 'background_color';
						$copy['colors'][]  = 'document_color';
						$copy['colors'][]  = 'document_background';
						break;
					case 'logo':
						$copy['content'][] = 'logo_show';
						$copy['content'][] = 'logo_image';
						$copy['content'][] = 'logo_url';
						$copy['content'][] = 'logo_alt';
						$copy['content'][] = 'logo_image_meta';
						$copy['design'][]  = 'logo_width';
						$copy['design'][]  = 'logo_opacity';
						$copy['design'][]  = 'logo_position';
						$copy['design'][]  = 'logo_margin_top';
						$copy['design'][]  = 'logo_margin_right';
						$copy['design'][]  = 'logo_margin_bottom';
						$copy['design'][]  = 'logo_margin_left';
						$copy['design'][]  = 'logo_rounded';
						$copy['colors'][]  = 'document_color';
						$copy['colors'][]  = 'document_background';
						break;
					case 'social_media':
						if (
						isset( $source_module_configuration['content'] )
						&& is_array( $source_module_configuration['content'] )
						) {
							foreach ( $source_module_configuration['content'] as $key => $value ) {
								if ( ! preg_match( '/^social_media_/', $key ) ) {
									continue;
								}
								$target_module_configuration['content'][ $key ] = $value;
							}
						}
						break;
					case 'social_media_settings':
						$copy['design'][] = 'social_media_show';
						$copy['design'][] = 'social_media_target';
						$copy['design'][] = 'social_media_colors';
						break;
					default:
						wp_send_json_error( array( 'message' => $this->messages['wrong'] ) );
				}
			}
			foreach ( $copy as $group => $data ) {
				if (
					! isset( $target_module_configuration[ $group ] )
					|| ! is_array( $target_module_configuration[ $group ] )
				) {
					$target_module_configuration[ $group ] = array();
				}
				foreach ( $data as $option ) {
					if ( isset( $source_module_configuration[ $group ][ $option ] ) ) {
						$target_module_configuration[ $group ][ $option ] = $source_module_configuration[ $group ][ $option ];
					}
				}
			}
			$message = array(
				'type'    => 'success',
				'message' => sprintf(
					__( 'Modul %s wurde aktualisiert.', 'ub' ),
					$this->bold( $target_module_data['name'] )
				),
			);
			$this->add_message( $message );
			pstoolkit_update_option( $target_module_data['options'][0], $target_module_configuration );
			wp_send_json_success();
		}

		/**
		 * Load dashboard
		 *
		 * @since 1.0.0
		 */
		public function load_dashboard() {
			$modules = get_pstoolkit_activated_modules( 'raw' );
			if ( empty( $modules ) ) {
				$user_id = get_current_user_id();
				$show    = get_user_meta( $user_id, 'show_welcome_dialog', true );
				$show    = empty( $show );
				if ( $show ) {
					$this->show_welcome_dialog = true;
					update_user_meta( $user_id, 'show_welcome_dialog', 'hide' );
				}
			}
		}

		/**
		 * PSToolkit Welcome!
		 *
		 * @since 1.0.0
		 */
		public function ajax_welcome() {
			$nonce = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( ! wp_verify_nonce( $nonce, 'pstoolkit-welcome-all-modules' ) ) {
				$args = array(
					'message' => $this->messages['security'],
				);
				wp_send_json_error( $args );
			}
			$args     = array(
				'dialog_id' => 'pstoolkit-welcome',
				'modules'   => $this->get_modules_stats(),
				'groups'    => pstoolkit_get_groups_list(),
			);
			$template = 'admin/dashboard/welcome-modules';
			$args     = array(
				'content'     => $this->render( $template, $args, true ),
				'title'       => esc_html__( 'Module aktivieren', 'ub' ),
				'description' => esc_html__( 'Wähle die Module aus, die Du aktivieren möchtest. Mit jedem Modul kannst Du einen bestimmten Teil Deiner Webseite mit einem White-Label versehen. Wenn Du Dir nicht sicher bist oder vergisst, ein Modul jetzt zu aktivieren, kannst Du dies später jederzeit tun.', 'ub' ),
			);
			wp_send_json_success( $args );
		}

		/**
		 * Check if high contrast mode is enabled.
		 *
		 * For the accessibility support, enable/disable
		 * high contrast support on admin area.
		 *
		 * @since 1.0.0
		 *
		 * @return bool
		 */
		private function high_contrast_mode() {
			// Get accessibility settings.
			$accessibility_options = pstoolkit_get_option( 'ub_accessibility', array() );
			if ( isset( $accessibility_options['accessibility']['high_contrast'] )
				&& 'on' === $accessibility_options['accessibility']['high_contrast'] ) {
				return true;
			}
			return false;
		}

		/**
		 * Common hooks for all screens
		 *
		 * @since 3.0.1
		 */
		public function add_action_hooks() {
			// Filter built-in psource branding script.
			add_filter( 'psource_whitelabel_plugin_pages', array( $this, 'builtin_psource_branding' ) );
		}

		/**
		 * Add more pages to builtin psource branding.
		 *
		 * @since 3.0.1
		 *
		 * @param array $plugin_pages Nextgen pages is not introduced in built in psource branding.
		 *
		 * @return array
		 */
		public function builtin_psource_branding( $plugin_pages ) {
			global $hook_suffix;
			if ( strpos( $hook_suffix, '_page_branding' ) ) {
				$plugin_pages[ $hook_suffix ] = array(
					'psource_whitelabel_sui_plugins_branding',
					'psource_whitelabel_sui_plugins_footer',
					'psource_whitelabel_sui_plugins_doc_links',
				);
			}
			return $plugin_pages;
		}

		/**
		 * Handle PSToolkit SUI wrapper container classes.
		 *
		 * @since 3.0.6
		 */
		public function add_sui_wrap_classes( $classes ) {
			if ( is_string( $classes ) ) {
				$classes = array( $classes );
			}
			if ( ! is_array( $classes ) ) {
				$classes = array();
			}
			$classes[] = 'sui-wrap';
			$classes[] = 'sui-wrap-pstoolkit';
			/**
			 * Add high contrast mode.
			 */
			$is_high_contrast_mode = $this->high_contrast_mode();
			if ( $is_high_contrast_mode ) {
				$classes[] = 'sui-color-accessible';
			}
			/**
			 * Set hide branding
			 *
			 * @since 3.0.6
			 */
			$hide_branding = apply_filters( 'psource_branding_hide_branding', $this->hide_branding );
			if ( $hide_branding ) {
				$classes[] = 'no-pstoolkit';
			}
			return $classes;
		}

		/**
		 * Delete image from modules, when it is deleted from ClassicPress
		 *
		 * @since 3.1.0
		 */
		public function delete_attachment_from_configs( $attachemnt_id ) {
			$affected_modules = array(
				'admin-bar',
				'db-error-page',
				'login-screen',
				'ms-site-check',
				'images',
				'maintenance',
			);
			foreach ( $this->configuration as $module ) {
				if ( ! in_array( $module['module'], $affected_modules ) ) {
					continue;
				}
				if ( ! isset( $module['options'] ) ) {
					continue;
				}
				foreach ( $module['options'] as $option_name ) {
					$value = pstoolkit_get_option( $option_name );
					if ( empty( $value ) ) {
						continue;
					}
					$update = false;
					foreach ( $value as $group => $group_data ) {
						if ( ! is_array( $group_data ) ) {
							continue;
						}
						foreach ( $group_data as $key => $field ) {
							switch ( $key ) {
								/**
								 * Single image
								 */
								case 'favicon':
								case 'logo_image':
								case 'logo':
									$field = intval( $field );
									if ( $attachemnt_id === $field ) {
										$update = true;
										unset( $value[ $group ][ $key ] );
										$key .= '_meta';
										if ( isset( $value[ $group ][ $key ] ) ) {
											unset( $value[ $group ][ $key ] );
										}
									}
									break;
								/**
								 * Background
								 */
								case 'content_background':
									if ( is_array( $field ) ) {
										foreach ( $field as $index => $one ) {
											$id = isset( $one['value'] ) ? intval( $one['value'] ) : 0;
											if ( $attachemnt_id === $id ) {
												if ( isset( $value[ $group ][ $key ] ) ) {
													$update = true;
													unset( $value[ $group ][ $key ][ $index ] );
												}
											}
										}
									}
									break;
								default:
							}
						}
					}
					if ( $update ) {
						pstoolkit_update_option( $option_name, $value );
					}
				}
			}
		}

		/**
		 * Should be shown "Manage All Modules" button?
		 * It's depends.
		 *
		 * @since 3.2.0
		 *
		 * @return boolean $show To show, or not to show, that is the * question.
		 */
		private function show_manage_all_modules_button() {
			$show = true;
			if ( $this->is_network && ! $this->is_network_admin ) {
				$show = false;
			}
			return apply_filters( 'pstoolkit_show_manage_all_modules_button', $show );
		}

		/**
		 * Get inline style for box summary-image div
		 *
		 * @since 3.2.0
		 * @return string
		 */
		public function get_box_summary_image_style() {
			$image_url = apply_filters( 'psource_branding_hero_image', null );
			if ( ! empty( $image_url ) ) {
				return 'background-image:url(' . esc_url( $image_url ) . ')';
			}
			return '';
		}

		/**
		 * Get modules with inline help
		 *
		 * @since 3.2.0
		 */
		private function get_helps_list() {
			$helps = array();
			$show  = true;
			if ( $this->is_network && ! $this->is_network_admin ) {
				$show = false;
			}
			if ( $show ) {
				$helps[] = 'dashboard';
			}
			foreach ( $this->configuration as $id => $module ) {
				if ( isset( $module['has-help'] ) && $module['has-help'] ) {
					$show = true;
					if ( $this->is_network && ! $this->is_network_admin ) {
						$show = apply_filters( 'pstoolkit_module_check_for_subsite', false, $id, $module );
					}
					if ( $show ) {
						$helps[] = sprintf( 'modules/%s', sanitize_title( $module['module'] ) );
					}
				}
			}
			return $helps;
		}

		/**
		 * Helper to get network admin permissions settings page.
		 *
		 * @since 3.2.0
		 */
		private function get_network_permissions_url() {
			$url = add_query_arg(
				array(
					'page'   => 'branding_group_data',
					'module' => 'permissions',
				),
				network_admin_url( 'admin.php' )
			);
			return $url;
		}
	}
}
