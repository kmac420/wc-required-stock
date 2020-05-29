<?php

namespace StockDependenciesForWooCommerceAdmin {

  class Admin {

    /* Custom Stock Dependencies */

     /**
      * 
      * @param string $sku
      *
      * Get the product object by SKU
      *
      */

    function get_product_by_sku($sku) {
      $product_id = wc_get_product_id_by_sku($sku);
      if ($product_id) {
        return wc_get_product($product_id);
      } else {
        return false;
      }
    }

    /**
     * 
     * @param WC_Product $product
     * 
     * Add the stock dependency field for simple products
     * 
     */

    function product_options_inventory_product_data( $product ) {

      global $post;
      if ( $post->post_type == "product" ) {
        $product = wc_get_product($post->ID);
        woocommerce_wp_hidden_input( array(
          'id'     => 'sdwc_product_stock_dependency',
          'class'  => "sdwc_product_stock_dependency",
          'name'   => "sdwc_product_stock_dependency",
          'value'  => $product->get_meta('_stock_dependency') ?? '',
        ) );
      }
    }

    /**
     *
     * @param int $loop
     * @param $variation_data
     * @param WC_Product $variation
     * 
     * Add the stock dependencies field for the variations' settings page.
     * 
     */

    function add_variation_dependency_inventory( $loop, $variation_data, $variation ) {

      $variation = wc_get_product( $variation );
      woocommerce_wp_hidden_input( array(
        'id'     => "sdwc_variation_stock_dependency-{$loop}",
        'class'  => "sdwc_variation_stock_dependency",
        'name'   => "sdwc_variation_stock_dependency[{$loop}]",
        'value'  => $variation->get_meta('_stock_dependency') ?? '',
	      ) );

    }

    /**
     * 
     * @param string $product_data
     * 
     * Validate that $product_data is proper JSON
     * 
     */

    function validate_product_data ($product_data) {
      json_decode($product_data);
      return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     *
     * @param WC_Product $product
     * 
     * Save the custom fields.
     * 
     */

    function admin_process_product_object( $product ) {

      if ( ! empty( $_POST['sdwc_product_stock_dependency'] ) ) {
        $product_data = sanitize_text_field(stripslashes($_POST['sdwc_product_stock_dependency']));
        if ( $this->validate_product_data($product_data)) {
          $product->update_meta_data( '_stock_dependency', $product_data );
          return true;
        } else {
          return false;
        }
      }
    }

    /**
     * 
     * Save custom variable fields.
     *
     * @param int $variation_id
     * @param int $i
     * 
     */

    function save_product_variation( $variation_id, $i ) {
      $variation = wc_get_product( $variation_id );
      if ( ! empty( $_POST['sdwc_variation_stock_dependency-'.$i] ) ) {
        $product_data = sanitize_text_field(stripslashes($_POST['sdwc_variation_stock_dependency-'.$i]));
        if ( $this->validate_product_data($product_data)) {
          $variation->update_meta_data( '_stock_dependency', $product_data);
          $variation->save();
          return true;
        } else {
          return false;
        }
      }
    }

    /**
     * 
     * @param int $quantity
     * @param WC_Product $product
     * 
     * Get the stock quantity of the product/variation by checking the stock quanties of the 
     * dependency products/variations and using the minimum of those. If there are no dependency
     * products/variations then simply return the product's/variation's actual quantity
     * 
     */

    function product_get_stock_quantity($quantity, $product) {
      if ($product->get_meta( '_stock_dependency')) {
        $stock_dependency_settings = json_decode($product->get_meta('_stock_dependency'));
        if ( $stock_dependency_settings->enabled) {
          foreach ($stock_dependency_settings->stock_dependency as $stock_dependency) {
            if ($stock_dependency->sku) {
              if ($this->get_product_by_sku($stock_dependency->sku)) {
                $dependency_product = $this->get_product_by_sku($stock_dependency->sku);
                if ($dependency_product) {
                  $dependency_product_available = $dependency_product->get_stock_quantity();
                  if ( !isset($temp_stock_quantity)) {
                    // $stock_dependency->qty should always be a positive, non-zero integer
                    $temp_stock_quantity = intdiv($dependency_product_available, $stock_dependency->qty);
                  } else {
                    $temp_stock_quantity = min($temp_stock_quantity, intdiv($dependency_product_available, $stock_dependency->qty));
                  }
                }
              } else {
                $temp_stock_quantity = 0;
                break;
              }
            }
          }
          $quantity = $temp_stock_quantity;
        }
      }
      return $quantity;
    }

    /**
     * 
     * @param bool $is_in_stock
     * @param WC_Product $product
     * 
     * Get the in-stock status of the product by checking the stock levels of the 
     * dependency products. If there are no stock dependency settings then simply return
     * the product's actual in-stock status. Handles simple and variable products.
     * 
     */

    function product_is_in_stock($is_in_stock, $product) {
     if ( $product->is_type('variable') && $product->has_child() && !$product->managing_stock()) {
        /** if the product type is variable, and the product has children (i.e. variations)
         *  and stock is not being managed at the product level (i.e. it is possibly being
         *  managed at the variation level) then check to see if there are stock dependencies
         *  for each of the variations that affect the stock status
         */
        foreach ($product->get_children() as $key => $variation_id) {
          $variation = wc_get_product($variation_id);
          if ( $variation->is_type('variation') && $variation->managing_stock()) {
            // $variation_check = $this->variation_is_in_stock($is_in_stock, $variation);
            $variation_check = $variation->is_in_stock();
            if ($variation_check) {
              /** if there is at least one variation that has stock then we will consider
               *  the variable product to be instock
               */
              $is_in_stock = true;
              break;
            }
          }
        }
        /** updated the stock_status value for the variable product as sometimes the stock_status
         *  in the DB gets out of sync e.g. when any of the products or variations on which this 
         *  product depends has had its stock depleted
        */
        if ($is_in_stock) {
          $product->set_stock_status('instock');
        } else {
          $product->set_stock_status('outofstock');
        }
      } else if (( $product->is_type('simple') || $product->is_type('variation')) && $product->managing_stock() ) {
        /** if the product is either a simple product or a product variation then and
         *  inventory is being managed then check if there are stock dependencies that
         *  affect the stock status
        */
        $stock_dependencies_enabled = false;
        /** get the stock dependency settings if they exist */
        if ($product->managing_stock() && $product->get_meta( '_stock_dependency')) {
          $stock_dependency_settings = json_decode($product->get_meta('_stock_dependency'));
          if (property_exists($stock_dependency_settings, 'enabled')) {
            $stock_dependencies_enabled = true;
          }
        }
        if ( $stock_dependencies_enabled) {
          // product has stock dependencies so check each dependency to see if in stock
          foreach ($stock_dependency_settings->stock_dependency as $stock_dependency) {
            if ($stock_dependency->sku) {
              if ($this->get_product_by_sku($stock_dependency->sku)) {
                $dependency_product = $this->get_product_by_sku($stock_dependency->sku);
                $dependency_product_available = $dependency_product->get_stock_quantity();
                if (intdiv($dependency_product_available, $stock_dependency->qty) === 0) {
                  $is_in_stock = false;
                  /** if there is at least one dependency that is not in stock then we will consider
                   *  the product or variation to be outofstock
                   */
                  break;
                } elseif (intdiv($dependency_product_available, $stock_dependency->qty) > 0) {
                  $is_in_stock = true;
                }
              } else {
                $is_in_stock =false;
                /** if we cannot get the product or variation dependency by SKU then we will consider
                 *  the product or variation to be outofstock
                 */
                break;
              }
            }
          }
        }
        /** updated the stock_status value for the variable product as sometimes the stock_status
         *  in the DB gets out of sync e.g. when any of the products or variations on which this 
         *  product depends has had its stock depleted
        */
        if ($is_in_stock) {
          $product->set_stock_status('instock');
        } else {
          $product->set_stock_status('outofstock');
        }
      }
      return $is_in_stock;
    }

    /**
     * 
     * @param bool $is_in_stock
     * @param WC_Product $product
     * 
     * Get the in-stock status of the variation by checking the stock levels of the 
     * dependency variations. If there are no stock dependency settings then simply return
     * the variation's actual in-stock status
     * 
     */

    function variation_is_in_stock($is_in_stock, $variation) {
      if ($variation->get_meta( '_stock_dependency')) {
        $stock_dependency_settings = json_decode($variation->get_meta('_stock_dependency'));
        if ( $stock_dependency_settings->enabled) {
          foreach ($stock_dependency_settings->stock_dependency as $stock_dependency) {
            if ($stock_dependency->sku) {
              if ($this->get_product_by_sku($stock_dependency->sku)) {
                $dependency_product = $this->get_product_by_sku($stock_dependency->sku);
                $dependency_product_available = $dependency_product->get_stock_quantity();
                if (intdiv($dependency_product_available, $stock_dependency->qty) === 0) {
                  $is_in_stock = false;
                  break;
                } elseif (intdiv($dependency_product_available, $stock_dependency->qty) > 0) {
                  $is_in_stock = true;
                }
              } else {
                $is_in_stock =false;
                break;
              }
            }
          }
        }
      }
      return $is_in_stock;
    }

    /**
     * 
     * @param string $status
     * @param WC_Product $product
     * 
     * hook filter: woocommerce_product_variation_get_stock_status
     * 
     * Get the stock status of the product/variation by checking the stock statuses of the 
     * dependency products/variations. If there are no dependency products/variations then simply
     * return the product's/variation's actual stock status
     * 
     */

    public function product_get_stock_status($status, $product) {
      if ($product->get_meta( '_stock_dependency')) {
        $stock_dependency_settings = json_decode($product->get_meta('_stock_dependency'));
        if ( $stock_dependency_settings->enabled) {
          foreach ($stock_dependency_settings->stock_dependency as $stock_dependency) {
            if ($stock_dependency->sku) {
              if ($this->get_product_by_sku($stock_dependency->sku)) {
                $dependency_product = $this->get_product_by_sku($stock_dependency->sku);
                $dependency_product_available = $dependency_product->get_stock_quantity();
                if (intdiv($dependency_product_available, $stock_dependency->qty) === 0) {
                  $status = "outofstock";
                  break;
                } elseif (intdiv($dependency_product_available, $stock_dependency->qty) > 0) {
                  $status = "instock";
                  break;
                }
              }
            } else {
              $status = "outofstock";
              break;
            }
          }
        }
      }
      return $status;
    }

    /**
     * hook action: reduce_order_stock
     * 
     * @param WC_Order $order
     * 
     * This action hook is called after the stock has been reduced for the order
     * being checked out. This function will reduce the inventory of the dependency
     * stock items by the number of items in the order times the number of dependency
     * stock for that item. Note that if the item in the order being checked out 
     * has dependency products, then the WooCommerce function wc_reduce_order_stock
     * will set the inventory quantity of that product to zero (0).
     * 
     */

    function reduce_order_stock($order) {
      $items = $order->get_items();
      // check each order item to see if there is stock dependency settings
      foreach ( $items as $item ) {
        $order_product = wc_get_product( $item['product_id'] );
        if ( $order_product->is_type('variable')) {
          $order_product = wc_get_product( $item['variation_id'] );
        }
        if ($order_product->get_meta( '_stock_dependency')) {
          $stock_dependency_settings_string = $order_product->get_meta('_stock_dependency');
          $stock_dependency_settings = json_decode($stock_dependency_settings_string);
          $order_item_qty = $item->get_quantity();
          if ( $stock_dependency_settings->enabled) {
            // for each stock dependency sku, decrease the stock by the correct amount
            // and create a note on the order
            foreach ($stock_dependency_settings->stock_dependency as $stock_dependency) {
              if ($stock_dependency->sku) {
                $dependency_product = $this->get_product_by_sku($stock_dependency->sku);
                $old_stock_quantity = $dependency_product->get_stock_quantity();
                $new_stock = wc_update_product_stock(
                  $dependency_product,
                  $order_item_qty * $stock_dependency->qty,
                  'decrease' );
                if ( is_wp_error( $new_stock ) ) {
                  $order->add_order_note( sprintf(
                    __('Unable to reduce stock for dependency SKU %s from %s to %s (by quantity %s)', 'woocommerce' ),
                    $dependency_product->get_sku(),
                    $old_stock_quantity,
                    $order_item_qty * $stock_dependency->qty )
                  );
                } else {
                  $order->add_order_note( sprintf(
                    __('Reduced order stock for dependency SKU %s from %s by quantity %s', 'woocommerce' ),
                    $dependency_product->get_sku(),
                    $old_stock_quantity,
                    $order_item_qty * $stock_dependency->qty )
                  );
                }
              }
            }
            // reset the ordered item stock level to 0
            $new_stock = wc_update_product_stock( $order_product, 0, 'set' );
            $order_product_sku = $order_product->get_sku();
            if ( is_wp_error( $new_stock ) ) {
              $order->add_order_note( sprintf(
                __('Unable to set stock for SKU %s to 0', 'woocommerce' ),
                $order_product_sku )
              );
            } else {
              $order->add_order_note( sprintf(
                __('Reset order stock for SKU %s to 0', 'woocommerce' ),
                $order_product_sku )
              );
            }
          }
          // Add the stock dependency settings to the order item so that if a return
          // is processed we will know the stock dependency settings that were used
          // for this order item and not assume that the stock dependency settings 
          // have not changed
          $add_order_item_meta = wc_add_order_item_meta(
          $item->get_id(),
          '_stock_dependency',
          $stock_dependency_settings_string,
          false
          );
        }
      }
    }

    /**
     * 
     * @param array $args
     * 
     * Hide stock dependencies order item meta data in WP admin
     * 
     */

    function hidden_order_itemmeta($args) {
      $args[] = '_stock_dependency';
      return $args;
    }

    /**
     * 
     * @param array $links
     * 
     */

    function actionLinks( $links ) {
      $links[] = '<a href="https://github.com/kmac420/stock-dependencies-for-woocommerce#stock-dependencies-for-woocommerce-plugin" target="_blank">Documentation</a>';
      return $links;
    }

    /**
     * 
     * @param string $hook
     * 
     * Enqueue the code and style files only to the edit.php admin page and only
     * if the post type is product
     * 
     */

    public function enqueu_scripts($hook) {
      if ( $hook == 'post.php') {
        global $post;
        $post_type = get_post_type( $post );
        if ( $post_type == 'product') {
          wp_enqueue_script('sdwc_admin_settings', plugins_url("/settings.js", __FILE__));
          wp_enqueue_style('sdwc_admin_styles', plugins_url("/admin.css", __FILE__));
        }
      }
    }

  }

}