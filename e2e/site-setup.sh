#!/bin/bash

# Site setup script for Newspack
# This script bootstraps a Newspack site with content and configuration

set -e

# Default configuration
POSTS_ENABLED=true
POSTS_COUNT=40
HOMEPAGE_ENABLED=true
USERS_ENABLED=true
USERS_COUNT=2
WOOCOMMERCE_ENABLED=true
CUSTOMERS_COUNT=100
MEMBERSHIP_PLANS_ENABLED=true
SUBSCRIPTIONS_ENABLED=true
SUBSCRIPTIONS_PERCENTAGE=80
CAMPAIGNS_ENABLED=true
MENUS_ENABLED=true
THEME="newspack-theme"

# Site / install configuration. Override with the flags below so this script can
# target any environment (local Docker, an isolated env, or a remote site over SSH)
# rather than a single hardcoded dev URL.
SITE_URL="http://localhost"
SITE_TITLE="Newspack Site"
ADMIN_USER="admin"
ADMIN_PASSWORD="password"
ADMIN_EMAIL="admin@example.com"

# Whether to pass --allow-root to WP-CLI. Required when running as root (e.g. inside
# a Docker container); must be OFF on managed hosts like Atomic, where it errors.
ALLOW_ROOT=false

# Reset strategy applied in Step 1:
#   full  - `wp db reset` (DROP + CREATE DATABASE). Needs privileges most managed
#           hosts don't grant; use locally.
#   clean - `wp db clean` (drop all tables, keep the database). Safe on Atomic.
#   none  - skip the DB wipe entirely (the caller has already reset the site).
RESET_MODE="full"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --no-posts)
            POSTS_ENABLED=false
            shift
            ;;
        --posts-count)
            POSTS_COUNT="$2"
            shift 2
            ;;
        --no-homepage)
            HOMEPAGE_ENABLED=false
            shift
            ;;
        --no-users)
            USERS_ENABLED=false
            shift
            ;;
        --users-count)
            USERS_COUNT="$2"
            shift 2
            ;;
        --no-woocommerce)
            WOOCOMMERCE_ENABLED=false
            shift
            ;;
        --customers-count)
            CUSTOMERS_COUNT="$2"
            shift 2
            ;;
        --no-membership-plans)
            MEMBERSHIP_PLANS_ENABLED=false
            shift
            ;;
        --no-subscriptions)
            SUBSCRIPTIONS_ENABLED=false
            shift
            ;;
        --subscriptions-percentage)
            SUBSCRIPTIONS_PERCENTAGE="$2"
            shift 2
            ;;
        --no-campaigns)
            CAMPAIGNS_ENABLED=false
            shift
            ;;
        --no-menus)
            MENUS_ENABLED=false
            shift
            ;;
        --block-theme)
            THEME="newspack-block-theme"
            shift
            ;;
        --url)
            SITE_URL="$2"
            shift 2
            ;;
        --title)
            SITE_TITLE="$2"
            shift 2
            ;;
        --admin-user)
            ADMIN_USER="$2"
            shift 2
            ;;
        --admin-password)
            ADMIN_PASSWORD="$2"
            shift 2
            ;;
        --admin-email)
            ADMIN_EMAIL="$2"
            shift 2
            ;;
        --allow-root)
            ALLOW_ROOT=true
            shift
            ;;
        --reset)
            RESET_MODE="$2"
            shift 2
            ;;
        --help)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --no-posts                  Disable posts creation"
            echo "  --posts-count N             Number of posts to create (default: 40)"
            echo "  --no-homepage               Disable homepage creation"
            echo "  --no-users                  Disable users creation"
            echo "  --users-count N             Number of users per role (default: 2)"
            echo "  --no-woocommerce            Disable WooCommerce setup"
            echo "  --customers-count N         Number of customers to create (default: 100)"
            echo "  --no-membership-plans       Disable membership plans creation"
            echo "  --no-subscriptions          Disable subscriptions creation"
            echo "  --subscriptions-percentage N Percentage of customers with subscriptions (default: 80)"
            echo "  --no-campaigns              Disable campaigns setup"
            echo "  --no-menus                  Disable menus creation"
            echo "  --block-theme               Use newspack-block-theme instead of newspack-theme"
            echo "  --url URL                   Site URL for the WordPress install (default: http://localhost)"
            echo "  --title TITLE               Site title (default: \"Newspack Site\")"
            echo "  --admin-user USER           Admin username (default: admin)"
            echo "  --admin-password PASS       Admin password (default: password)"
            echo "  --admin-email EMAIL         Admin email (default: admin@example.com)"
            echo "  --allow-root                Pass --allow-root to WP-CLI (running as root, e.g. in Docker)"
            echo "  --reset MODE                DB reset strategy: full | clean | none (default: full)"
            echo "  --help                      Show this help message"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            exit 1
            ;;
    esac
done

# WP-CLI wrapper: injects global flags (currently --allow-root) into every call so
# the rest of the script can invoke `wp ...` without repeating them, and so the same
# script works both as root in a container and unprivileged on a managed host.
WP_GLOBAL_OPTS=()
if [ "$ALLOW_ROOT" = true ]; then
    WP_GLOBAL_OPTS+=(--allow-root)
fi
wp() {
    command wp "${WP_GLOBAL_OPTS[@]}" "$@"
}

# Step 1: Reset the database
case "$RESET_MODE" in
    full)
        log_info "Step 1: Resetting the database (drop + recreate)..."
        wp db reset --yes || {
            log_error "Failed to reset database"
            exit 1
        }
        log_success "Database reset completed"
        ;;
    clean)
        log_info "Step 1: Cleaning the database (dropping all tables)..."
        wp db clean --yes || {
            log_error "Failed to clean database"
            exit 1
        }
        log_success "Database cleaned"
        ;;
    none)
        log_info "Step 1: Skipping database reset (--reset none)"
        ;;
    *)
        log_error "Invalid --reset mode: $RESET_MODE (expected full | clean | none)"
        exit 1
        ;;
esac

# Flush the object cache before reinstalling. On hosts with a persistent object
# cache (e.g. Atomic's memcached), dropping the tables leaves stale options like
# `siteurl` cached, so `wp core install` would report "already installed" and skip
# recreating the tables. Flushing ensures the fresh install actually runs.
wp cache flush || true

# Reinstall WordPress
log_info "Reinstalling WordPress..."
wp core install --url="$SITE_URL" --title="$SITE_TITLE" --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" --admin_email="$ADMIN_EMAIL" --skip-email || {
    log_error "Failed to reinstall WordPress"
    exit 1
}
log_success "WordPress reinstalled"

# Activate theme first (before plugins, to ensure theme_mods are set correctly)
log_info "Activating theme: $THEME..."
wp theme activate $THEME || {
    log_error "Failed to activate $THEME"
    exit 1
}
log_success "$THEME activated"

# Activate Newspack plugins
log_info "Activating Newspack plugins..."
wp plugin activate newspack-plugin || {
    log_error "Failed to activate Newspack plugin"
    exit 1
}
log_success "Newspack plugin activated"

wp plugin activate newspack-blocks || {
    log_error "Failed to activate Newspack Blocks plugin"
    exit 1
}
log_success "Newspack Blocks plugin activated"

wp plugin activate newspack-popups || {
    log_error "Failed to activate Newspack Popups plugin"
    exit 1
}
log_success "Newspack Popups plugin activated"

# Mark Newspack setup as complete
log_info "Marking Newspack setup as complete..."
wp option update newspack_setup_complete 1
log_success "Newspack setup marked as complete"

# Enable Reader Activation (required for reader-registration block)
log_info "Enabling Reader Activation..."
wp option update newspack_reader_activation_enabled 1
log_success "Reader Activation enabled"

# Remove default Sample Page
log_info "Removing default Sample Page..."
wp post delete $(wp post list --post_type=page --name=sample-page --field=ID --format=ids 2>/dev/null) --force 2>/dev/null || true
log_success "Sample Page removed"

# Step 2: Create posts and categories
if [ "$POSTS_ENABLED" = true ]; then
    log_info "Step 2: Creating $POSTS_COUNT posts with categories..."
    wp eval '
        if ( class_exists( "Newspack\Starter_Content_Generated" ) ) {
            Newspack\Starter_Content_Generated::create_categories();
            echo "Categories created\n";

            // Get starter category IDs to distribute posts across them.
            global $wpdb;
            $category_ids = array_column(
                $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT term_id FROM $wpdb->terms WHERE slug LIKE %s",
                        "_newspack_%"
                    )
                ),
                "term_id"
            );
            $category_count = count( $category_ids );

            for ( $i = 0; $i < '$POSTS_COUNT'; $i++ ) {
                $post_id = Newspack\Starter_Content_Generated::create_post( $i );
                // Assign post to a starter category (cycling through them).
                if ( $category_count > 0 ) {
                    $category_id = $category_ids[ $i % $category_count ];
                    wp_set_post_categories( $post_id, [ $category_id ] );
                }
                echo "Post " . ( $i + 1 ) . " of '$POSTS_COUNT' created\n";
            }
        } else {
            echo "Starter_Content_Generated class not found\n";
            exit(1);
        }
    ' || {
        log_error "Failed to create posts and categories"
        exit 1
    }
    log_success "Posts and categories created"
else
    log_info "Step 2: Skipping posts creation (disabled)"
fi

# Step 3: Create homepage
if [ "$HOMEPAGE_ENABLED" = true ]; then
    log_info "Step 3: Creating homepage..."
    wp eval '
        if ( class_exists( "Newspack\Starter_Content_Generated" ) ) {
            Newspack\Starter_Content_Generated::create_homepage();
            set_theme_mod( "hide_front_page_title", true );
            echo "Homepage created\n";
        } else {
            echo "Starter_Content_Generated class not found\n";
            exit(1);
        }
    ' || {
        log_error "Failed to create homepage"
        exit 1
    }
    log_success "Homepage created"
else
    log_info "Step 3: Skipping homepage creation (disabled)"
fi

# Step 4: Create users
if [ "$USERS_ENABLED" = true ]; then
    log_info "Step 4: Creating users..."

    # Create Guest Contributors
    for i in $(seq 1 $USERS_COUNT); do
        wp user create guest_contributor_$i guest_contributor_$i@example.com --role=contributor_no_edit --display_name="Guest Contributor $i" || {
            log_warning "Failed to create guest contributor $i (may already exist)"
        }
    done
    log_success "Created $USERS_COUNT guest contributors"

    # Create Editors
    for i in $(seq 1 $USERS_COUNT); do
        wp user create editor_$i editor_$i@example.com --role=editor --display_name="Editor $i" || {
            log_warning "Failed to create editor $i (may already exist)"
        }
    done
    log_success "Created $USERS_COUNT editors"

    # Create Authors
    for i in $(seq 1 $USERS_COUNT); do
        wp user create author_$i author_$i@example.com --role=author --display_name="Author $i" || {
            log_warning "Failed to create author $i (may already exist)"
        }
    done
    log_success "Created $USERS_COUNT authors"

    # Create Subscribers
    for i in $(seq 1 $USERS_COUNT); do
        wp user create subscriber_$i subscriber_$i@example.com --role=subscriber --display_name="Subscriber $i" || {
            log_warning "Failed to create subscriber $i (may already exist)"
        }
    done
    log_success "Created $USERS_COUNT subscribers"
else
    log_info "Step 4: Skipping users creation (disabled)"
fi

# Step 5: WooCommerce setup
if [ "$WOOCOMMERCE_ENABLED" = true ]; then
    log_info "Step 5: Setting up WooCommerce..."

    # Activate WooCommerce plugins
    log_info "Activating WooCommerce plugins..."
    PLUGINS=("woocommerce" "woocommerce-subscriptions" "woocommerce-memberships" "woocommerce-name-your-price")

    for plugin in "${PLUGINS[@]}"; do
        # Put the check directly in the `if` so a "not installed" result (non-zero
        # exit) doesn't trip `set -e` and abort the whole script.
        if wp plugin is-installed "$plugin" 2>/dev/null; then
            wp plugin activate "$plugin" || {
                log_warning "Failed to activate $plugin"
            }
            log_success "Activated $plugin"
        else
            log_warning "Plugin $plugin is not installed"
        fi
    done

    # Complete WooCommerce setup
    log_info "Completing WooCommerce setup..."
    wp eval '
        update_option( "woocommerce_store_address", "123 Main St" );
        update_option( "woocommerce_store_city", "San Francisco" );
        update_option( "woocommerce_default_country", "US:CA" );
        update_option( "woocommerce_store_postcode", "94102" );
        update_option( "woocommerce_currency", "USD" );
        update_option( "woocommerce_product_type", "both" );
        update_option( "woocommerce_allow_tracking", "no" );
        update_option( "woocommerce_onboarding_profile", array( "completed" => true ) );
        echo "WooCommerce setup completed\n";
    '

    # Setup Newspack Donations
    log_info "Setting up Newspack Donations..."
    wp eval '
        if ( class_exists( "Newspack\Donations" ) ) {
            // Get donation page info
            $page_info = Newspack\Donations::get_donation_page_info();
            if ( ! empty( $page_info["id"] ) ) {
                // Publish the donations page
                wp_update_post( array(
                    "ID" => $page_info["id"],
                    "post_status" => "publish"
                ) );
                update_option( "newspack_donation_page_id", $page_info["id"] );
                echo "Donations page published\n";
            }

            // Create donation products
            Newspack\Donations::update_donation_product();
            echo "Donation products created\n";

            // Update billing fields
            update_option( "newspack_donations_billing_fields", array(
                "billing_email",
                "billing_first_name",
                "billing_last_name"
            ) );
            echo "Billing fields updated\n";
        } else {
            echo "Donations class not found\n";
        }
    ' || {
        log_warning "Failed to setup Newspack Donations completely"
    }

    # Create WooCommerce entities
    if [ "$CUSTOMERS_COUNT" -gt 0 ]; then
        log_info "Creating $CUSTOMERS_COUNT customers with orders..."
        wp eval '
            for ( $i = 1; $i <= '$CUSTOMERS_COUNT'; $i++ ) {
                $customer_id = wc_create_new_customer(
                    "customer_" . $i . "@example.com",
                    "customer_" . $i,
                    "password123",
                    array(
                        "first_name" => "Customer",
                        "last_name" => "Number " . $i
                    )
                );

                if ( ! is_wp_error( $customer_id ) ) {
                    // Create an order for each customer
                    $order = wc_create_order( array(
                        "customer_id" => $customer_id,
                        "status" => "completed"
                    ) );

                    if ( $order ) {
                        // Add a donation product to the order
                        $donation_product_id = Newspack\Donations::get_donation_product( "once" );
                        if ( $donation_product_id ) {
                            $order->add_product( wc_get_product( $donation_product_id ), 1, array(
                                "subtotal" => 25,
                                "total" => 25
                            ) );
                            $order->calculate_totals();
                            $order->save();
                        }
                    }

                    if ( $i % 10 == 0 ) {
                        echo "Created " . $i . " of '$CUSTOMERS_COUNT' customers\n";
                    }
                } else {
                    echo "Failed to create customer " . $i . "\n";
                }
            }
            echo "All customers created\n";
        ' || {
            log_warning "Failed to create all customers"
        }
        log_success "Customers created"
    fi

    # Create Membership Plans. Needs the WooCommerce Memberships plugin; skip
    # gracefully when it isn't active rather than aborting on the first
    # `wp wc memberships` call (which doesn't exist without the plugin).
    if [ "$MEMBERSHIP_PLANS_ENABLED" = true ] && ! wp plugin is-active woocommerce-memberships 2>/dev/null; then
        log_warning "Skipping membership plans: woocommerce-memberships is not active"
    elif [ "$MEMBERSHIP_PLANS_ENABLED" = true ]; then
        log_info "Creating membership plans..."

        # Registration Wall plan
        PLAN_ID=$(wp wc memberships plan create --name="Registration Wall" --access="signup" 2>/dev/null | grep -o '[0-9]*')
        if [ ! -z "$PLAN_ID" ]; then
            wp wc memberships plan rule create \
                --plan="$PLAN_ID" \
                --type="content_restriction" \
                --target="post_type:post"
            echo "Registration Wall plan created with ID: $PLAN_ID"
        else
            log_warning "Failed to create Registration Wall plan"
        fi

        # Premium Content plan
        # Create Premium Content category
        CATEGORY_ID=$(wp term create category "Premium Content" --porcelain 2>/dev/null)

        # Get donation product IDs
        MONTH_PRODUCT=$(wp eval 'echo Newspack\Donations::get_donation_product("month");')
        YEAR_PRODUCT=$(wp eval 'echo Newspack\Donations::get_donation_product("year");')

        # Create Golden Plan
        PLAN_ID=$(wp wc memberships plan create --name="Golden Plan" --access="purchase" --product="$MONTH_PRODUCT,$YEAR_PRODUCT" 2>/dev/null | grep -o '[0-9]*')

        if [ ! -z "$PLAN_ID" ] && [ ! -z "$CATEGORY_ID" ]; then
            wp wc memberships plan rule create \
                --plan="$PLAN_ID" \
                --type="content_restriction" \
                --target="taxonomy:category" \
                --object_ids="$CATEGORY_ID"
            echo "Golden Plan created with Premium Content category"
        else
            log_warning "Failed to create Premium Content plan"
        fi

        log_success "Membership plans created"
    fi

    # Create Subscriptions
    if [ "$SUBSCRIPTIONS_ENABLED" = true ] && [ "$CUSTOMERS_COUNT" -gt 0 ]; then
        log_info "Creating subscriptions for ${SUBSCRIPTIONS_PERCENTAGE}% of customers..."

        SUBSCRIPTION_COUNT=$((CUSTOMERS_COUNT * SUBSCRIPTIONS_PERCENTAGE / 100))
        YEARLY_COUNT=$((SUBSCRIPTION_COUNT * 20 / 100))
        MONTHLY_COUNT=$((SUBSCRIPTION_COUNT - YEARLY_COUNT))

        wp eval '
            $yearly_product_id = Newspack\Donations::get_donation_product( "year" );
            $monthly_product_id = Newspack\Donations::get_donation_product( "month" );

            if ( ! $yearly_product_id || ! $monthly_product_id ) {
                echo "Donation products not found\n";
                exit(1);
            }

            // Find the Golden Plan (membership plan linked to donation products)
            $golden_plan_id = null;
            if ( function_exists( "wc_memberships_get_membership_plans" ) ) {
                $plans = wc_memberships_get_membership_plans();
                foreach ( $plans as $plan ) {
                    if ( $plan->get_name() === "Golden Plan" ) {
                        $golden_plan_id = $plan->get_id();
                        break;
                    }
                }
            }

            $customers = get_users( array(
                "role" => "customer",
                "number" => '$SUBSCRIPTION_COUNT'
            ) );

            $index = 0;
            foreach ( $customers as $customer ) {
                $product_id = ( $index < '$YEARLY_COUNT' ) ? $yearly_product_id : $monthly_product_id;
                $period = ( $index < '$YEARLY_COUNT' ) ? "year" : "month";
                $amount = ( $index < '$YEARLY_COUNT' ) ? 100 : 10;

                $subscription = wcs_create_subscription( array(
                    "customer_id" => $customer->ID,
                    "status" => "active",
                    "billing_period" => $period,
                    "billing_interval" => 1
                ) );

                if ( $subscription && ! is_wp_error( $subscription ) ) {
                    $product = wc_get_product( $product_id );
                    if ( $product ) {
                        $subscription->add_product( $product, 1, array(
                            "subtotal" => $amount,
                            "total" => $amount
                        ) );
                        $subscription->calculate_totals();
                        $subscription->save();

                        // Create membership for this customer, linked to the subscription
                        if ( $golden_plan_id && function_exists( "wc_memberships_create_user_membership" ) ) {
                            $membership = wc_memberships_create_user_membership( array(
                                "plan_id" => $golden_plan_id,
                                "user_id" => $customer->ID,
                                "order_id" => $subscription->get_id(),
                            ) );

                            // Link membership to subscription via meta
                            if ( $membership && ! is_wp_error( $membership ) ) {
                                update_post_meta( $membership->get_id(), "_subscription_id", $subscription->get_id() );
                            }
                        }
                    }
                }

                $index++;
                if ( $index % 10 == 0 ) {
                    echo "Created " . $index . " of '$SUBSCRIPTION_COUNT' subscriptions with memberships\n";
                }
            }
            echo "All subscriptions and memberships created\n";
        ' || {
            log_warning "Failed to create all subscriptions"
        }

        log_success "Subscriptions created ($YEARLY_COUNT yearly, $MONTHLY_COUNT monthly)"
    fi
else
    log_info "Step 5: Skipping WooCommerce setup (disabled)"
fi

# Step 6: Campaigns setup
if [ "$CAMPAIGNS_ENABLED" = true ]; then
    log_info "Step 6: Setting up campaigns..."

    # Create RAS defaults
    wp eval '
        if ( class_exists( "Newspack_Popups_Presets" ) ) {
            Newspack_Popups_Presets::activate_ras_presets();
            echo "RAS presets activated\n";
        } else {
            echo "Newspack_Popups_Presets class not found\n";
        }
    ' || {
        log_warning "Failed to create RAS presets"
    }

    log_success "Campaigns setup completed"
else
    log_info "Step 6: Skipping campaigns setup (disabled)"
fi

# Step 7: Create menus
if [ "$MENUS_ENABLED" = true ]; then
    log_info "Step 7: Creating menus..."

    wp eval '
        // Create below-header menu
        $menu_name = "Below Header Menu";
        $menu_id = wp_create_nav_menu( $menu_name );

        if ( ! is_wp_error( $menu_id ) ) {
            // Pages to exclude from menu
            $exclude_ids = array_filter( [
                get_option( "page_on_front" ),      // Homepage
                get_option( "woocommerce_cart_page_id" ),
                get_option( "woocommerce_checkout_page_id" ),
                get_option( "woocommerce_shop_page_id" ),
            ] );

            // Get all top-level pages excluding specific ones
            $pages = get_pages( array(
                "parent" => 0,
                "sort_column" => "menu_order",
                "sort_order" => "ASC",
                "exclude" => $exclude_ids
            ) );

            $position = 1;
            foreach ( $pages as $page ) {
                wp_update_nav_menu_item( $menu_id, 0, array(
                    "menu-item-title" => $page->post_title,
                    "menu-item-object" => "page",
                    "menu-item-object-id" => $page->ID,
                    "menu-item-type" => "post_type",
                    "menu-item-status" => "publish",
                    "menu-item-position" => $position++
                ) );
            }

            // Assign menu to location
            $locations = get_theme_mod( "nav_menu_locations" );
            $locations["secondary-menu"] = $menu_id;
            set_theme_mod( "nav_menu_locations", $locations );

            echo "Below-header menu created with " . count( $pages ) . " pages\n";
        } else {
            echo "Failed to create menu\n";
        }
    ' || {
        log_warning "Failed to create menus"
    }

    log_success "Menus created"
else
    log_info "Step 7: Skipping menus creation (disabled)"
fi

log_success "Site setup completed successfully!"