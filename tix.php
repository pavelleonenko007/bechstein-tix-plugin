<?php

/**
 * Plugin Name: Bechstein Tix Integration
 * Description: Plugin for integration site with tix system
 * Version: 1.0.0
 * Text Domain: btix
 */
if (!defined('ABSPATH')) {
  exit;
}

class BechTix
{
  public function __construct()
  {
    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    add_action('init', array($this, 'register_post_types'));
    add_filter('manage_tickets_posts_columns', array($this, 'add_ticket_id_column'));
    add_filter('manage_tickets_posts_custom_column', array($this, 'manage_custom_tickets_column'), 10, 2);
    add_action('admin_menu', array($this, 'add_menu_pages'));
    register_activation_hook(__FILE__, array($this, 'activation'));
    add_action('add_meta_boxes', array($this, 'add_ticket_metaboxes'));
    add_action('save_post', array($this, 'save_post_meta'));
    add_action('save_post_events', array($this, 'set_event_terms_to_related_tickets'));
    add_action('save_post_events', array($this, 'set_event_festival_to_related_tickets'));
    add_action('save_post_tickets', array($this, 'add_event_terms_to_ticket'));
    add_action('wp_after_insert_post', array($this, 'add_event_festival_to_ticket'));
    add_action('rest_api_init', array($this, 'register_rest_route_for_parse_data'));
  }

  public function activation()
  {
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }

  public function enqueue_scripts()
  {
    wp_enqueue_script('bechtix-scripts', plugin_dir_url(__FILE__) . 'assets/app.js', array('jquery'), time(), true);
    wp_register_style('bechtix-styles', plugin_dir_url(__FILE__) . 'assets/style.css', false, time());
    wp_enqueue_style('bechtix-styles');
  }

  public function register_post_types()
  {
    register_post_type('events', array(
      'labels'             => array(
        'name'               => 'Events',
        'singular_name'      => 'Event',
        'add_new'            => 'Create new',
        'add_new_item'       => 'Create new event',
        'edit_item'          => 'Edit event',
        'edit'               => 'Edit',
        'new_item'           => 'New Event',
        'view_item'          => 'View Event',
        'search_items'       => 'Search Events',
        'not_found'          => 'Not found',
        'not_found_in_trash' => 'Not found in trash',
        'view'               => 'View',
      ),
      'public'             => true,
      'show_ui'            => true,
      'show_in_menu'       => false,
      'menu_icon'          => 'dashicons-tickets',
      'publicly_queryable' => true,
      'hierarchical'       => false,
      'supports'            => array('title', 'editor', 'custom-fields'),
      'query_var'          => true,
    ));

    register_post_type('festivals', array(
      'labels'             => array(
        'name'               => 'Festivals',
        'singular_name'      => 'Festival',
        'add_new'            => 'Create new',
        'add_new_item'       => 'Create new festival',
        'edit_item'          => 'Edit festival',
        'edit'               => 'Edit',
        'new_item'           => 'New Festival',
        'view_item'          => 'View Festival',
        'search_items'       => 'Search Festivals',
        'not_found'          => 'Not found',
        'not_found_in_trash' => 'Not found in trash',
        'view'               => 'View',
      ),
      'public'             => true,
      'show_ui'            => true,
      'show_in_menu'       => false,
      'menu_icon'          => 'dashicons-tickets',
      'publicly_queryable' => true,
      'hierarchical'       => false,
      'supports'            => array('title', 'thumbnail', 'custom-fields'),
      'query_var'          => true,
    ));

    register_post_type('tickets', array(
      'labels'             => array(
        'name'               => 'Tickets',
        'singular_name'      => 'Ticket',
        'add_new'            => 'Create new',
        'add_new_item'       => 'Create new ticket',
        'edit_item'          => 'Edit ticket',
        'edit'               => 'Edit',
        'new_item'           => 'New Ticket',
        'view_item'          => 'View Ticket',
        'search_items'       => 'Search Tickets',
        'not_found'          => 'Not found',
        'not_found_in_trash' => 'Not found in trash',
        'view'               => 'View',
      ),
      'public'             => true,
      'show_ui'            => true,
      'show_in_menu'       => false,
      'publicly_queryable' => true,
      'hierarchical'       => false,
      'query_var'          => true,
    ));

    remove_post_type_support('events', 'editor');
    remove_post_type_support('tickets', 'editor');
  }

  public function add_ticket_id_column($columns)
  {
    $bechtix_columns = [
      'bechtix_event_id' => 'Event ID',
    ];

    return array_slice($columns, 0, 2) + $bechtix_columns + $columns;
  }

  public function manage_custom_tickets_column($column_name, $post_id)
  {
    if ($column_name === 'bechtix_event_id') {
      echo get_post_meta($post_id, '_bechtix_ticket_id', true);
    }
    return $column_name;
  }

  public function add_menu_pages()
  {
    add_menu_page(null, 'Events', 'edit_posts', '/edit.php?post_type=events', null, 'dashicons-tickets', 10);
    add_submenu_page('edit.php?post_type=events', null, 'Tickets', 'edit_posts', '/edit.php?post_type=tickets', null);
    // add_submenu_page('edit.php?post_type=events', null, 'Categories', 'edit_posts', '/edit-tags.php?taxonomy=event_cat&post_type=events', null);

    /* Tags renamed to categories */
    add_submenu_page('edit.php?post_type=events', null, 'Categories', 'edit_posts', '/edit-tags.php?taxonomy=event_tag&post_type=events', null);
    add_submenu_page('edit.php?post_type=events', null, 'Genres', 'edit_posts', '/edit-tags.php?taxonomy=genres&post_type=events', null);
    add_submenu_page('edit.php?post_type=events', null, 'Instruments', 'edit_posts', '/edit-tags.php?taxonomy=instruments&post_type=events', null);
    add_submenu_page('edit.php?post_type=events', null, 'Festivals', 'edit_posts', '/edit.php?post_type=festivals', null);
  }

  public function set_event_terms_to_related_tickets($event_id) {
    $related_ticket_ids = get_posts(array(
      'post_type' => 'tickets',
      'numberposts' => -1,
      'meta_key' => '_bechtix_event_relation',
      'meta_value' => $event_id,
      'fields' => 'ids'
    ));

    if (empty($related_ticket_ids)) {
      return;
    }
    
    $taxonomies = get_post_taxonomies($event_id);
    $terms = wp_get_object_terms($event_id, $taxonomies);

    foreach ($related_ticket_ids as $ticket_id) {
      foreach ($terms as $term) {
        wp_add_object_terms($ticket_id, $term->term_id, $term->taxonomy);
      }
    }
  }

  public function add_event_terms_to_ticket($post_id)
  { 
    $event_id = get_post_meta($post_id, '_bechtix_event_relation', true);

    if (empty($event_id)) {
      return;
    }

    $taxonomies = get_post_taxonomies($event_id);
    $terms = wp_get_post_terms($event_id, $taxonomies);

    foreach ($terms as $term) {
      wp_add_object_terms($post_id, $term->term_id, $term->taxonomy);
    }
  }

  public function set_event_festival_to_related_tickets($event_id) {
    $related_ticket_ids = get_posts(array(
      'post_type' => 'tickets',
      'numberposts' => -1,
      'meta_key' => '_bechtix_event_relation',
      'meta_value' => $event_id,
      'fields' => 'ids'
    ));

    if (empty($related_ticket_ids)) {
      return;
    }

    $festival_id = get_post_meta($event_id, '_bechtix_festival_relation', true);

    if (empty($festival_id)) {
      return;
    }

    foreach ($related_ticket_ids as $ticket_id) {
      update_post_meta($ticket_id, '_bechtix_festival_relation', $festival_id);
    }
  }

  public function add_event_festival_to_ticket($post_id) {
    if ('tickets' !== get_post_type($post_id)) {
      return;
    }

    $event_id = get_post_meta($post_id, '_bechtix_event_relation', true);

    if (empty($event_id)) {
      return;
    }

    $festival_id = get_post_meta($event_id, '_bechtix_festival_relation', true);

    if (empty($festival_id)) {
      return;
    }

    update_post_meta($post_id, '_bechtix_festival_relation', $festival_id);
  }

  public function register_rest_route_for_parse_data()
  {
    register_rest_route('tix-webhook/v1', '/webhook', array(
      'methods'             => 'GET',
      'callback'            => array($this, 'webhook_callback'),
      'permission_callback' => '__return_true'
    ));
  }

  public function webhook_callback()
  {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $url      = 'https://eventapi.tix.uk/v2/Events/39c4703fb4a64c7e';
    $response = wp_remote_get($url);

    if (is_dir(get_home_path() . 'tix-logs') === false) {
      mkdir(get_home_path() . 'tix-logs');
    }

    $file = fopen(get_home_path() . 'tix-logs/logs.txt', "a");

    fwrite($file, '=== Start ===' . PHP_EOL);
    fwrite($file, 'New connection at ' . date('c') . PHP_EOL);
    fwrite($file, '=== Status ===' . PHP_EOL);

    if (is_wp_error($response)) {
      fwrite($file, 'Fetching failed with error code ' . $response->get_error_code() . '. Message: ' . $response->get_error_message() . PHP_EOL);
      fwrite($file, '=== End ===' . PHP_EOL);
      fclose($file);

      return new WP_Error('can_not_fetch_data', 'Can not fetch data from tix', ['status' => 400]);
    }

    fwrite($file, 'Code: ' . $response['response']['code'] . '. Message: ' . $response['response']['message'] . PHP_EOL);

    fwrite($file, '=== End ===' . PHP_EOL);
    fclose($file);

    $body = json_decode($response['body'], true);

    foreach ($body as $event) {
      $existed_events = get_posts(array(
        'post_type' => 'events',
        'post_status' => 'publish',
        'meta_key' => '_bechtix_event_group_id',
        'meta_value' => $event['EventGroupId']
      ));

      $event_args = array(
        'post_type' => 'events',
        'post_status' => 'publish',
        'post_author' => 1,
        'post_title' => $event['Name'],
        'post_content' => '<p></p>'
      );

      if (!empty($existed_events)) {
        $event_args['ID'] = $existed_events[0]->ID;
      }

      $event_id = wp_insert_post($event_args, true);

      if (is_wp_error($event_id)) {
        return new WP_Error(500, $event_id->get_error_message());
      }

      update_post_meta($event_id, '_bechtix_event_group_id', $event['EventGroupId']);

      // if ($event['EventImagePath'] && $event_image_id = $this->upload_images_by_url($event['EventImagePath'])) {
      //   update_post_meta($event_id, '_bechtix_event_image', $event_image_id);
      // }

      // if ($event['FeaturedImagePath'] && $featured_image_id = $this->upload_images_by_url($event['FeaturedImagePath'])) {
      //   update_post_meta($event_id, '_bechtix_featured_image', $featured_image_id);
      // }

      foreach ($event['Dates'] as $ticket) {
        $existed_tickets = get_posts(array(
          'post_type' => 'tickets',
          'post_status' => 'publish',
          'meta_key' => '_bechtix_ticket_id',
          'meta_value' => $ticket['EventId']
        ));

        $ticket_args = array(
          'post_type' => 'tickets',
          'post_status' => 'publish',
          'post_author' => 1,
          'post_title' => $ticket['Name'],
          'post_content' => '<p></p>'
        );

        if (!empty($existed_tickets)) {
          $ticket_args['ID'] = $existed_tickets[0]->ID;
        }

        $ticket_id = wp_insert_post($ticket_args, true);

        if (is_wp_error($ticket_id)) {
          return new WP_Error(500, $ticket_id->get_error_message());
        }

        update_post_meta($ticket_id, '_bechtix_event_relation', $event_id);

        if (!empty($ticket['EventId'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_id', $ticket['EventId']);
        }

        if (isset($ticket['Duration'])) {
          update_post_meta($ticket_id, '_bechtix_duration', $ticket['Duration']);
        }

        if (!empty($ticket['StartDateUTCUnix'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_start_date', gmdate('Y-m-d H:i:s', $ticket['StartDateUTCUnix']));
        }

        if (!empty($ticket['EndDateUTCUnix'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_end_date', gmdate('Y-m-d H:i:s', $ticket['EndDateUTCUnix']));
        }

        if (!empty($ticket['OnlineSaleStartUTCUnix'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_online_sale_start', gmdate('Y-m-d H:i:s', $ticket['OnlineSaleStartUTCUnix']));
        }

        if (!empty($ticket['OnlineSaleStartUTCUnix'])) {
          $online_sale_start_dates = [];
          $online_sale_start_dates[] = [
            'date' => gmdate('Y-m-d H:i:s', $ticket['OnlineSaleStartUTCUnix']),
            'customer_id' => ''
          ];

          if (!empty($ticket['Benefits'])) {
            $online_sale_start_dates = array_merge($online_sale_start_dates, $this->formatBenefitsToDates($ticket['Benefits']));
          }

          update_field('online_dates', $online_sale_start_dates, $ticket_id);

          // update_post_meta($ticket_id, '_bechtix_ticket_start_dates', wp_json_encode($online_sale_start_dates));
        }

        if (!empty($ticket['OnlineSaleEndUTCUnix'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_online_sale_end', gmdate('Y-m-d H:i:s', $ticket['OnlineSaleEndUTCUnix']));
        }

        if (isset($ticket['SaleStatus'])) {
          update_post_meta($ticket_id, '_bechtix_sale_status', (string) $ticket['SaleStatus']);
        }

        if (!empty($ticket['MinPrice'])) {
          update_post_meta($ticket_id, '_bechtix_min_price', $ticket['MinPrice']);
        }

        if (!empty($ticket['MaxPrice'])) {
          update_post_meta($ticket_id, '_bechtix_max_price', $ticket['MaxPrice']);
        }

        if (!empty($ticket['Benefits'])) {
          update_post_meta($ticket_id, '_bechtix_ticket_benefits', wp_json_encode($ticket['Benefits']));
        }

        if (!empty($ticket['PurchaseUrls'])) {
          $formatted_array = $this->format_purchase_urls_data($ticket['PurchaseUrls']);
          update_post_meta($ticket_id, '_bechtix_purchase_urls', wp_json_encode($formatted_array));
        }

				if (isset($ticket['WaitingList'])) {
					update_post_meta($ticket_id, '_bechtix_in_waiting_list', (int) $ticket['WaitingList']);
				}
      }
    }

    $rest_response = rest_ensure_response([
      'code'    => 'success',
      'message' => 'Data succesfully updated',
      'data'    => [
        'status' => 201
      ]
    ]);

    $rest_response->set_status(201);

    return $rest_response;
  }

  public function formatBenefitsToDates(array $benefits): array
  {
    return array_map(function ($benefit) {
      return [
        'date' => gmdate('Y-m-d H:i:s', $benefit['OnlineSaleStartUTCUnix']),
        'customer_id' => $benefit['CustomerTag']['CustomerTagId']
      ];
    }, array_filter($benefits, function ($benefit) {
      return isset($benefit['OnlineSaleStartUTCUnix']) && !empty($benefit['OnlineSaleStartUTCUnix']);
    }));
  }

  // public function upload_images_by_url($image_url)
  // {
  //   // it allows us to use download_url() and wp_handle_sideload() functions
  //   require_once(ABSPATH . 'wp-admin/includes/file.php');

  //   // download to temp dir
  //   $temp_file = download_url($image_url);

  //   if (is_wp_error($temp_file)) {
  //     return false;
  //   }

  //   // move the temp file into the uploads directory
  //   $file = array(
  //     'name'     => basename($image_url),
  //     'type'     => mime_content_type($temp_file),
  //     'tmp_name' => $temp_file,
  //     'size'     => filesize($temp_file),
  //   );
  //   $sideload = wp_handle_sideload(
  //     $file,
  //     array(
  //       'test_form'   => false // no needs to check 'action' parameter
  //     )
  //   );

  //   if (!empty($sideload['error'])) {
  //     // you may return error message if you want
  //     return false;
  //   }

  //   // it is time to add our uploaded image into WordPress media library
  //   $attachment_id = wp_insert_attachment(
  //     array(
  //       'guid'           => $sideload['url'],
  //       'post_mime_type' => $sideload['type'],
  //       'post_title'     => basename($sideload['file']),
  //       'post_content'   => '',
  //       'post_status'    => 'inherit',
  //     ),
  //     $sideload['file']
  //   );

  //   if (is_wp_error($attachment_id) || !$attachment_id) {
  //     return false;
  //   }

  //   // update medatata, regenerate image sizes
  //   require_once(ABSPATH . 'wp-admin/includes/image.php');

  //   wp_update_attachment_metadata(
  //     $attachment_id,
  //     wp_generate_attachment_metadata($attachment_id, $sideload['file'])
  //   );

  //   return $attachment_id;
  // }

  public function format_purchase_urls_data($arr)
  {
    $formatted_arr = [];

    foreach ($arr as $item) {
      $formatted_item                       = [];
      $formatted_item['lang'] = $item['TwoLetterCulture'];
      $formatted_item['link']               = $item['Link'];

      $formatted_arr[] = $formatted_item;
    }

    return $formatted_arr;
  }

  public function add_ticket_metaboxes()
  {
    add_meta_box(
      'bechtix_event_group_id',
      'Event Group ID',
      array($this, 'add_event_group_id_field'),
      'events'
    );

    add_meta_box(
      'bechtix_event_description',
      'Event Description',
      array($this, 'add_event_description_field'),
      'events'
    );

    add_meta_box(
      'bechtix_event_relation',
      'Event',
      array($this, 'add_event_relation_select'),
      'tickets'
    );

    add_meta_box(
      'bechtix_festival_relation',
      'Festival',
      array($this, 'add_festival_relation_select'),
      ['events', 'tickets']
    );

    add_meta_box(
      'bechtix_ticket_id',
      'Ticket ID',
      array($this, 'add_bechtix_ticket_id_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_online_sale_start',
      'Online Sale Start',
      array($this, 'add_online_sale_start_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_online_sale_end',
      'Online Sale End',
      array($this, 'add_online_sale_end_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_start_date',
      'Start Date',
      array($this, 'add_ticket_start_date_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_end_date',
      'End Date',
      array($this, 'add_ticket_end_date_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_purchase_urls',
      'Purchase URLs',
      array($this, 'add_purchase_urls_fields'),
      'tickets'
    );

    // add_meta_box(
    //   'bechtix_event_image',
    //   'Event Image',
    //   array($this, 'add_event_image_field'),
    //   array('events', 'tickets')
    // );

    // add_meta_box(
    //   'bechtix_featured_image',
    //   'Featured Image',
    //   array($this, 'add_featured_image_field'),
    //   array('events', 'tickets')
    // );

    add_meta_box(
      'bechtix_sale_status',
      'Sale Status',
      array($this, 'add_sale_status_select'),
      'tickets'
    );

    add_meta_box(
      'bechtix_duration',
      'Duration',
      array($this, 'add_duration_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_event_duration_info',
      'Duration Info',
      array($this, 'add_event_duration_info_field'),
      'events'
    );

    add_meta_box(
      'bechtix_ticket_min_price',
      'Min Price',
      array($this, 'add_min_price_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_max_price',
      'Max Price',
      array($this, 'add_max_price_field'),
      'tickets'
    );

    add_meta_box(
      'bechtix_ticket_benefits',
      'Ticket benefits',
      array($this, 'add_ticket_benefits_field'),
      'tickets'
    );

		add_meta_box(
      'bechtix_waiting_list',
      'Waiting List',
      array($this, 'add_bechtix_waiting_list_checkbox'),
      'tickets'
    );

    add_meta_box(
      'bechtix_festival_dates',
      'Festival Dates',
      array($this, 'add_bechtix_festival_dates_field'),
      'festivals'
    );

    add_meta_box(
      'bechtix_festival_note',
      'Festival Note',
      array($this, 'add_bechtix_festival_note_field'),
      'festivals'
    );
  }

  public function add_event_group_id_field($post)
  {
    $group_id = get_post_meta($post->ID, '_bechtix_event_group_id', true);
    echo $this->get_input_field('_bechtix_event_group_id', 'text', $group_id);
  }

  public function add_event_description_field($post)
  {
    $description = get_post_meta($post->ID, '_bechtix_event_description', true);
    echo $this->get_input_field('_bechtix_event_description', 'text', $description);
  }

  public function get_input_field($field_id, $type = 'text', $value = '')
  {
    $id = mb_substr($field_id, 1);

		switch ($type) {
			case 'checkbox':
				$checked = !empty($value) ? 'checked' : '';
				return "<input type=\"$type\" style=\"margin-top: 0;\" class=\"bechtix-checkbox\" name=\"$id\" id=\"$id\" $checked />";
			default:
				return '<input type="' . $type . '" class="bechtix-text-field" name="' . $id . '" id="' . $id . '" value="' . $value . '" />';
		}
  }

  public function add_event_relation_select($post)
  {
    $event_id = get_post_meta($post->ID, '_bechtix_event_relation', true);
?>
    <select name="bechtix_event_relation" id="bechtix_event_relation">
      <option value="">Select something...</option>
      <?php $events = get_posts(array(
        'post_type' => 'events',
        'post_status' => 'publish',
        'numberposts' => -1
      ));

      foreach ($events as $event) : ?>
        <option value="<?php echo $event->ID; ?>" <?php selected($event_id, $event->ID); ?>><?php echo $event->post_title; ?></option>
      <?php endforeach; ?>
    </select>
  <?php }

  public function add_festival_relation_select($post)
  {
    $festival_id = get_post_meta($post->ID, '_bechtix_festival_relation', true);
  ?>
    <select name="bechtix_festival_relation" id="bechtix_festival_relation">
      <option value="">Select Festival</option>
      <?php $festivals = get_posts(array(
        'post_type' => 'festivals',
        'post_status' => 'publish',
        'numberposts' => -1
      ));

      foreach ($festivals as $festival) : ?>
        <option value="<?php echo $festival->ID; ?>" <?php selected($festival_id, $festival->ID); ?>><?php echo $festival->post_title; ?></option>
      <?php endforeach; ?>
    </select>
  <?php
  }
  public function add_bechtix_ticket_id_field($post)
  {
    $ticket_id = get_post_meta($post->ID, '_bechtix_ticket_id', true);
  ?>
    <input type="text" class="bechtix-text-field" name="bechtix_ticket_id" id="bechtix_ticket_id" value="<?php echo $ticket_id; ?>" />
  <?php }

  public function add_online_sale_start_field($post)
  {
    $online_sale_start = get_post_meta($post->ID, '_bechtix_ticket_online_sale_start', true);
    $formatted_date = '';

    if ($online_sale_start) {
      $unix = strtotime($online_sale_start);
      $formatted_date = substr(gmdate('c', $unix), 0, 16);
    }
  ?>
    <input type="datetime-local" name="bechtix_ticket_online_sale_start" id="bechtix_ticket_online_sale_start" value="<?php echo $formatted_date; ?>" />
  <?php
  }

  public function add_online_sale_end_field($post)
  {
    $online_sale_end = get_post_meta($post->ID, '_bechtix_ticket_online_sale_end', true);
    $formatted_date = '';

    if ($online_sale_end) {
      $unix = strtotime($online_sale_end);
      $formatted_date = substr(gmdate('c', $unix), 0, 16);
    }
  ?>
    <input type="datetime-local" name="bechtix_ticket_online_sale_end" id="bechtix_ticket_online_sale_end" value="<?php echo $formatted_date; ?>" />
  <?php
  }

  public function add_ticket_start_date_field($post)
  {
    $start_date = get_post_meta($post->ID, '_bechtix_ticket_start_date', true);
    $formatted_date = '';
    if ($start_date) {
      $unix = strtotime($start_date);
      $formatted_date = substr(gmdate('c', $unix), 0, 16);
    }
  ?>
    <input type="datetime-local" name="bechtix_ticket_start_date" id="bechtix_ticket_start_date" value="<?php echo $formatted_date; ?>" />
  <?php
  }

  public function add_ticket_end_date_field($post)
  {
    $end_date = get_post_meta($post->ID, '_bechtix_ticket_end_date', true);
    $formatted_date = '';
    if ($end_date) {
      $unix = strtotime($end_date);
      $formatted_date = substr(gmdate('c', $unix), 0, 16);
    }
  ?>
    <input type="datetime-local" name="bechtix_ticket_end_date" id="bechtix_ticket_end_date" value="<?php echo $formatted_date; ?>" />
    <?php
  }

  public function add_purchase_urls_fields($post)
  {
    $button_id = bin2hex(random_bytes(5));
    $purchase_urls = json_decode(get_post_meta($post->ID, '_bechtix_purchase_urls', true), true);
    echo '<div><fieldset>';
    if (!empty($purchase_urls)) {
      foreach ($purchase_urls as $index => $purchase_url) { ?>
        <div id="bechtix-row-<?php echo $index; ?>" class="bechtix-row">
          <div class="bechtix-row__container">
            <div class="bechtix-field">
              <label for="lang-abbr-<?php echo $index; ?>" class="label">
                2-letter language abbreviation
              </label>
              <input type="text" id="lang-abbr-<?php echo $index; ?>" name="purchase_urls[<?php echo $index; ?>][lang]" value="<?php echo $purchase_url['lang']; ?>">
            </div>
            <div class="bechtix-field">
              <label for="purchase-link-<?php echo $index; ?>" class="label">
                Link
              </label>
              <input type="text" id="purchase-link-<?php echo $index; ?>" name="purchase_urls[<?php echo $index; ?>][link]" value="<?php echo $purchase_url['link']; ?>">
            </div>
          </div>
          <button type="button" class="bechtix-row__remove">×</button>
        </div>
      <?php }
    } else { ?>
      <div id="bechtix-row-0" class="bechtix-row">
        <div class="bechtix-row__container">
          <div class="bechtix-field">
            <label for="lang-abbr-0" class="label">
              2-letter language abbreviation
            </label>
            <input type="text" id="lang-abbr-0" name="purchase_urls[0][lang]" value="">
          </div>
          <div class="bechtix-field">
            <label for="purchase-link-0" class="label">
              Link
            </label>
            <input type="text" id="purchase-link-0" name="purchase_urls[0][link]" value="">
          </div>
        </div>
        <button type="button" class="bechtix-row__remove">×</button>
      </div>
    <?php }
    echo '</fieldset>
      <button id="' . $button_id . '" type="button" class="bechtix-button button button-primary">Add Row</button>
    </div>
    <script>
      document.getElementById("' . $button_id . '").onclick = event => {
        event.preventDefault();
        const rowsContainer = event.target.previousElementSibling;
        const rowsLength = parseInt(rowsContainer.querySelector(".bechtix-row:last-child").id.slice(-1)) + 1;
        rowsContainer.insertAdjacentHTML("beforeend", `
          <div id="bechtix-row-${rowsLength}" class="bechtix-row">
            <div class="bechtix-row__container">
              <div class="bechtix-field">
                <label for="lang-abbr-${rowsLength}" class="label">
                  2-letter language abbreviation
                </label>
                <input type="text" id="lang-abbr-${rowsLength}" name="purchase_urls[${rowsLength}][lang]" value="">
              </div>
              <div class="bechtix-field">
                <label for="purchase-link-${rowsLength}" class="label">
                  Link
                </label>
                <input type="text" id="purchase-link-${rowsLength}" name="purchase_urls[${rowsLength}][link]" value="">
              </div>
            </div>
            <button type="button" class="bechtix-row__remove">×</button>
          </div>
        `);
      }
    </script>';
  }

  /*

  public function add_event_image_field($post)
  {
    $event_image_id = get_post_meta($post->ID, '_bechtix_event_image', true);
    if ($image = wp_get_attachment_image_url($event_image_id, 'medium')) : ?>
      <a href="#" class="bechtix-button-upload">
        <img src="<?php echo esc_url($image) ?>" />
      </a>
      <a href="#" class="bechtix-button-remove">Remove image</a>
      <input type="hidden" name="bechtix_event_image" value="<?php echo absint($event_image_id) ?>">
    <?php else : ?>
      <a href="#" class="button bechtix-button-upload">Upload image</a>
      <a href="#" class="bechtix-button-remove" style="display:none">Remove image</a>
      <input type="hidden" name="bechtix_event_image" value="">
    <?php endif;
  }

  // public function add_featured_image_field($post)
  // {
  //   $featured_image_id = get_post_meta($post->ID, '_bechtix_featured_image', true);
  //   if ($image = wp_get_attachment_image_url($featured_image_id, 'medium')) : ?>
  //     <a href="#" class="bechtix-button-upload">
  //       <img src="<?php echo esc_url($image) ?>" />
  //     </a>
  //     <a href="#" class="bechtix-button-remove">Remove image</a>
  //     <input type="hidden" name="bechtix_featured_image" value="<?php echo absint($featured_image_id) ?>">
  //   <?php else : ?>
  //     <a href="#" class="button bechtix-button-upload">Upload image</a>
  //     <a href="#" class="bechtix-button-remove" style="display:none">Remove image</a>
  //     <input type="hidden" name="bechtix_featured_image" value="">
  //   <?php endif;
  // }

  */
  public function add_sale_status_select($post)
  {
    $sale_status = get_post_meta($post->ID, '_bechtix_sale_status', true);
    $statuses = [
      'No Status',
      'Few tickets',
      'Sold out',
      'Cancelled',
      'Not scheduled'
    ];
    ?>
    <select name="bechtix_sale_status" id="bechtix_sale_status">
      <?php foreach ($statuses as $index => $status) { ?>
        <option value="<?php echo $index; ?>" <?php selected($sale_status, $index); ?>><?php echo $status; ?></option>
      <?php } ?>
    </select>
  <?php
  }

  public function add_duration_field($post)
  {
    $duration = get_post_meta($post->ID, '_bechtix_duration', true);
  ?>
    <input type="text" name="bechtix_duration" id="bechtix_duration" value="<?php echo $duration; ?>" />
  <?php
  }

  public function add_event_duration_info_field($post)
  {
    $event_duration = get_post_meta($post->ID, '_bechtix_event_duration_info', true);
  ?>
    <input type="text" name="bechtix_event_duration_info" id="bechtix_event_duration_info" value="<?php echo $event_duration; ?>" />
  <?php
  }

  public function add_min_price_field($post)
  {
    $min_price = get_post_meta($post->ID, '_bechtix_min_price', true);
    echo $this->get_input_field('_bechtix_min_price', 'text', $min_price);
  }

  public function add_max_price_field($post)
  {
    $max_price = get_post_meta($post->ID, '_bechtix_max_price', true);
    echo $this->get_input_field('_bechtix_max_price', 'text', $max_price);
  }

  public function add_ticket_benefits_field($post)
  {
    $benefits = get_post_meta($post->ID, '_bechtix_ticket_benefits', true);
  ?>
    <textarea id="bechtix_ticket_benefits" name="bechtix_ticket_benefits" style="width: 100%;" rows="10"><?php echo _wp_specialchars($benefits, ENT_QUOTES, 'UTF-8', true); ?></textarea>
<?php
  }

  public function add_bechtix_festival_dates_field($post)
  {
    $festival_dates = get_post_meta($post->ID, '_bechtix_festival_dates', true);
    echo $this->get_input_field('_bechtix_festival_dates', 'text', $festival_dates);
  }

  public function add_bechtix_festival_note_field($post)
  {
    $festival_note = get_post_meta($post->ID, '_bechtix_festival_note', true);
    echo $this->get_input_field('_bechtix_festival_note', 'text', $festival_note);
  }

	public function add_bechtix_waiting_list_checkbox($post)
  {
    $is_in_waiting_list = (bool) get_post_meta($post->ID, '_bechtix_in_waiting_list', true);
		echo "<label style=\"display: flex; align-items: center; gap: 10px\">
			<span style=\"font-weight: 500;\">In Waiting List</span>";
    echo $this->get_input_field('_bechtix_in_waiting_list', 'checkbox', $is_in_waiting_list);
		echo "</label>";
  }

  public function save_post_meta($post_id)
  {
    if (array_key_exists('bechtix_event_group_id', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_event_group_id',
        $_POST['bechtix_event_group_id']
      );
    }

    if (array_key_exists('bechtix_event_description', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_event_description',
        $_POST['bechtix_event_description']
      );
    }

    if (array_key_exists('bechtix_event_relation', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_event_relation',
        $_POST['bechtix_event_relation']
      );
    }

    if (array_key_exists('bechtix_festival_relation', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_festival_relation',
        $_POST['bechtix_festival_relation']
      );
    }

    if (array_key_exists('bechtix_ticket_id', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_ticket_id',
        $_POST['bechtix_ticket_id']
      );
    }

    if (array_key_exists('bechtix_ticket_online_sale_start', $_POST)) {
      $unix = strtotime($_POST['bechtix_ticket_online_sale_start']);
      $formatted_time = gmdate('Y-m-d H:i:s', $unix);
      update_post_meta(
        $post_id,
        '_bechtix_ticket_online_sale_start',
        $formatted_time
      );
    }

    if (array_key_exists('bechtix_ticket_online_sale_end', $_POST)) {
      $unix = strtotime($_POST['bechtix_ticket_online_sale_end']);
      $formatted_time = gmdate('Y-m-d H:i:s', $unix);
      update_post_meta(
        $post_id,
        '_bechtix_ticket_online_sale_end',
        $formatted_time
      );
    }

    if (array_key_exists('bechtix_ticket_start_date', $_POST)) {
      $unix = strtotime($_POST['bechtix_ticket_start_date']);
      $formatted_time = gmdate('Y-m-d H:i:s', $unix);
      update_post_meta(
        $post_id,
        '_bechtix_ticket_start_date',
        $formatted_time
      );
    }

    if (array_key_exists('bechtix_ticket_end_date', $_POST)) {
      $unix = strtotime($_POST['bechtix_ticket_end_date']);
      $formatted_time = gmdate('Y-m-d H:i:s', $unix);
      update_post_meta(
        $post_id,
        '_bechtix_ticket_end_date',
        $formatted_time
      );
    }

    if (array_key_exists('purchase_urls', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_purchase_urls',
        wp_json_encode(array_values($_POST['purchase_urls']))
      );
    }

    if (array_key_exists('bechtix_event_image', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_event_image',
        $_POST['bechtix_event_image']
      );
    }

    if (array_key_exists('bechtix_featured_image', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_featured_image',
        $_POST['bechtix_featured_image']
      );
    }

    if (array_key_exists('bechtix_sale_status', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_sale_status',
        $_POST['bechtix_sale_status']
      );
    }

    if (array_key_exists('bechtix_duration', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_duration',
        $_POST['bechtix_duration']
      );
    }

    if (array_key_exists('bechtix_event_duration_info', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_event_duration_info',
        $_POST['bechtix_event_duration_info']
      );
    }

    if (array_key_exists('bechtix_min_price', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_min_price',
        $_POST['bechtix_min_price']
      );
    }

    if (array_key_exists('bechtix_max_price', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_max_price',
        $_POST['bechtix_max_price']
      );
    }

    if (array_key_exists('bechtix_ticket_benefits', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_ticket_benefits',
        $_POST['bechtix_ticket_benefits']
      );
    }

		update_post_meta(
			$post_id,
			'_bechtix_in_waiting_list',
			(int) isset($_POST['bechtix_in_waiting_list'])
		);

    if (array_key_exists('bechtix_festival_dates', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_festival_dates',
        $_POST['bechtix_festival_dates']
      );
    }

    if (array_key_exists('bechtix_festival_note', $_POST)) {
      update_post_meta(
        $post_id,
        '_bechtix_festival_note',
        $_POST['bechtix_festival_note']
      );
    }
  }
}

new BechTix();

