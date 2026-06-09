<?php
/**
 * Plugin Name: Perfinn YCP Checkout для Яндекса
 * Plugin URI:  https://github.com/perfinn/perfinn-ycp-checkout
 * Description: Неофициальная интеграция магазина с Яндекс Чекаутом по протоколу YCP. Все 10 эндпоинтов API v1, автосоздание заказов, лог запросов. Плагин не связан с компанией Yandex.
 * Version:     1.0.0
 * Author:      Perfinn
 * Author URI:  https://perfinn.ru
 * Support:     info@perfinn.ru
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: perfinn-ycp-checkout
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 10.6
 */

if (!defined('ABSPATH')) exit;

class YCP_Yandex_Commerce_Woo
{
    const OPT_TOKEN = 'ycpy_token';
    const OPT_LOG   = 'ycpy_log';
    const ENDPOINT  = 'ycp';

    public static function init(): void
    {
        add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);
        add_action('admin_menu',     [__CLASS__, 'menu']);
        add_action('admin_init',     [__CLASS__, 'register_settings']);
        add_action('init',           [__CLASS__, 'register_endpoint']);
        add_action('parse_request',  [__CLASS__, 'handle_request'], 1);

        register_activation_hook(__FILE__, function () {
            YCP_Yandex_Commerce_Woo::register_endpoint();
            flush_rewrite_rules();
        });
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain('perfinn-ycp-checkout', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function menu(): void
    {
        add_management_page(
            'YCP Yandex',
            'YCP Yandex Commerce',
            'manage_options',
            'ycp-yandex',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('ycpy', self::OPT_TOKEN,        ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('ycpy', 'ycpy_wh_title',        ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('ycpy', 'ycpy_wh_address',      ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('ycpy', 'ycpy_wh_phone',        ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('ycpy', 'ycpy_wh_description',  ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('ycpy', 'ycpy_wh_self_pickup',  ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']);
    }

    public static function register_endpoint(): void
    {
        add_rewrite_rule('^' . self::ENDPOINT . '/?$',         'index.php?ycp_yandex=1', 'top');
        add_rewrite_rule('^' . self::ENDPOINT . '/([^/]+)/?$', 'index.php?ycp_yandex=1&ycp_yandex_action=$matches[1]', 'top');
        add_rewrite_tag('%ycp_yandex%',        '([0-9]+)');
        add_rewrite_tag('%ycp_yandex_action%', '([^/]+)');

        // Однократный flush после деплоя
        if (get_option('ycp_yandex_rewrite_v') !== '1.0.0') {
            flush_rewrite_rules(false);
            update_option('ycp_yandex_rewrite_v', '1.0.0');
        }
    }

    public static function handle_request(\WP $wp): void
    {
        // Совместимость: либо через rewrite (?ycp_yandex=1), либо через прямой path /ycp/*
        $is_ycp   = !empty($wp->query_vars['ycp_yandex']);
        $req_uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $req_path = $req_uri ? (parse_url($req_uri, PHP_URL_PATH) ?: '') : '';
        if (!$is_ycp) {
            if (preg_match('~^/' . preg_quote(self::ENDPOINT, '~') . '(/|$)~', $req_path)) {
                $is_ycp = true;
            }
        }
        if (!$is_ycp) return;

        $token = (string) get_option(self::OPT_TOKEN, '');
        if (!$token) self::respond(401, ['error' => 'YCP token not configured on site']);

        // Bearer-токен в Authorization header (или X-API-Key как fallback)
        $auth_header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        $bearer = '';
        if (preg_match('/Bearer\s+(.+)/i', $auth_header, $m)) $bearer = trim($m[1]);
        if (!$bearer && !empty($_SERVER['HTTP_X_API_KEY'])) {
            $bearer = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_API_KEY']));
        }

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        $action = (string)($wp->query_vars['ycp_yandex_action'] ?? '');
        if (!$action) {
            $action = trim(str_replace('/' . self::ENDPOINT, '', $req_path), '/');
        }

        // Читаем тело запроса (Yandex YCP отправляет JSON). Жёстко ограничиваем длину,
        // отбрасываем непечатные байты и тэги — то, что попадёт в лог и БД, гарантированно безопасно.
        $body_raw = (string) file_get_contents('php://input');
        if (strlen($body_raw) > 100000) {
            $body_raw = substr($body_raw, 0, 100000);
        }
        $body = json_decode($body_raw, true);

        // Для логирования — отдельная sanitized-копия (никогда не парсится повторно)
        $body_log = wp_check_invalid_utf8(wp_strip_all_tags($body_raw), true);
        $body_log = mb_substr((string) $body_log, 0, 4000, 'UTF-8');

        // Лог запроса
        self::log_entry($method, $action, $bearer ? '***' . substr($bearer, -6) : 'no-token', $body_log);

        // Проверка токена (constant-time)
        if (!$bearer || !hash_equals($token, $bearer)) {
            self::respond(401, ['error' => 'Invalid or missing Bearer token']);
        }

        // Нормализуем action — убираем префикс api/vN/
        $norm = preg_replace('~^api/v\d+/~', '', $action);
        $method = strtoupper($method);

        // Маршрутизация по YCP-контракту v1
        switch ($norm) {
            case '':
            case 'ping':
            case 'healthcheck':
                self::respond(200, [
                    'status'  => 'ok',
                    'service' => parse_url(home_url(), PHP_URL_HOST) ?: '',
                    'version' => '1.0.0',
                    'time'    => current_time('c'),
                ]);

            case 'warehouses':
                self::handle_warehouses();

            case 'checkout/basket/check':
                self::handle_basket_check($body);

            case 'checkout/delivery/options':
                self::handle_delivery_options($body);

            case 'checkout/delivery/pickup_points':
                self::handle_pickup_points();

            case 'checkout':
                self::handle_checkout_create($body);

            case 'checkout/placed':
                self::handle_checkout_placed($body);

            case 'checkout/cancel':
                self::handle_checkout_cancel($body);

            case 'order':
                self::handle_order_get();

            case 'order/cancel':
                self::handle_order_cancel($body);

            case 'order/delivered':
                self::handle_order_delivered($body);

            default:
                self::respond(404, ['error' => 'Unknown action: ' . $action]);
        }
    }

    // ============================================================
    // GET /warehouses
    // ============================================================
    private static function handle_warehouses(): void
    {
        $wh = [[
            'id'          => 'main',
            'title'       => (string) get_option('ycpy_wh_title',   get_bloginfo('name') . ' — основной склад'),
            'address'     => (string) get_option('ycpy_wh_address', get_option('woocommerce_store_city', 'Россия')),
            'phone'       => (string) get_option('ycpy_wh_phone',   ''),
            'description' => (string) get_option('ycpy_wh_description', ''),
            'self_pickup_options' => [
                'enabled' => (bool) get_option('ycpy_wh_self_pickup', false),
            ],
        ]];
        self::respond(200, ['warehouses' => $wh, 'total_count' => 1]);
    }

    // ============================================================
    // POST /checkout/basket/check
    // ============================================================
    private static function handle_basket_check($body): void
    {
        $items_in = is_array($body) && isset($body['items']) ? $body['items'] : [];
        $out = ['items' => []];
        foreach ($items_in as $item) {
            $id  = (string)($item['id'] ?? '');
            $qty = (int)($item['quantity'] ?? 1);
            $pid = self::resolve_product_id($id);
            $p   = $pid ? wc_get_product($pid) : null;
            if (!$p) {
                $out['items'][] = [
                    'id'         => $id,
                    'name'       => '',
                    'warehouses' => [['id' => 'main', 'available_quantity' => 0]],
                ];
                continue;
            }
            $available = $p->is_in_stock()
                ? ($p->managing_stock() ? max(0, (int)$p->get_stock_quantity()) : max($qty, 1))
                : 0;
            $regular = (float) $p->get_regular_price() ?: (float) $p->get_price();
            $final   = (float) $p->get_price();
            $img_id  = $p->get_image_id();
            $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'full') : '';

            // Габариты обуви по умолчанию (коробка): 32×22×13 см, 850 г
            $w = (int) round((float) $p->get_width()  * 10); if ($w <= 0) $w = 320;
            $h = (int) round((float) $p->get_height() * 10); if ($h <= 0) $h = 130;
            $d = (int) round((float) $p->get_length() * 10); if ($d <= 0) $d = 220;
            $kg = (int) round((float) $p->get_weight() * 1000); if ($kg <= 0) $kg = 850;

            $out['items'][] = [
                'id'             => $id,
                'name'           => $p->get_name(),
                'regular_price'  => (int) round($regular),
                'final_price'    => (int) round($final),
                'warehouses'     => [['id' => 'main', 'available_quantity' => max(1, $available)]],
                'image'          => $img_url ?: '',
                'url'            => get_permalink($p->get_id()),
                'width'          => $w,
                'height'         => $h,
                'depth'          => $d,
                'weight'         => $kg,
                'characteristics'=> [],
                'variations'     => [],
            ];
        }
        self::respond(200, $out);
    }

    // ============================================================
    // POST /checkout/delivery/options
    // ============================================================
    private static function handle_delivery_options($body): void
    {
        $method = (string)($body['delivery_method'] ?? 'courier');
        $now = time();
        $tomorrow = $now + 86400;
        $day_after = $now + 86400 * 2;

        $options = [];
        if ($method === 'pickup_point') {
            $options[] = [
                'id' => 'pickup-default',
                'cost' => 0,
                'delivery_date_interval' => [
                    'date_from' => gmdate('Y-m-d', $tomorrow),
                    'date_to'   => gmdate('Y-m-d', $day_after),
                    'time_zone' => 3,
                ],
            ];
        } else {
            $options[] = [
                'id' => 'courier-standard',
                'cost' => 400,
                'delivery_date_interval' => [
                    'date_from' => gmdate('Y-m-d', $tomorrow),
                    'date_to'   => gmdate('Y-m-d', $day_after),
                    'time_from' => '10:00',
                    'time_to'   => '22:00',
                    'time_zone' => 3,
                ],
            ];
        }
        self::respond(200, ['delivery_options' => $options]);
    }

    // ============================================================
    // GET /checkout/delivery/pickup_points
    // ============================================================
    private static function handle_pickup_points(): void
    {
        // Своих ПВЗ нет — Яндекс использует свои.
        self::respond(200, ['pickup_points' => [], 'total_count' => 0]);
    }

    // ============================================================
    // POST /checkout — создание сессии (резерв заказа) → 201
    // ============================================================
    private static function handle_checkout_create($body): void
    {
        try {
            self::do_checkout_create($body);
        } catch (\Throwable $e) {
            error_log('[YCP-Yandex] checkout exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $log = (array) get_option(self::OPT_LOG, []);
            $log[] = ['time'=>current_time('Y-m-d H:i:s'),'method'=>'EXCEPTION','action'=>'checkout','token'=>'','ip'=>'','body'=>$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()];
            update_option(self::OPT_LOG, array_slice($log,-100), false);
            self::respond(500, ['error' => 'checkout failed: ' . $e->getMessage()]);
        }
    }

    private static function do_checkout_create($body): void
    {
        // session_id может прийти как session_id или приниматься без него — fallback на uuid
        $session_id = (string)($body['session_id'] ?? '');
        if (!$session_id) {
            // Если Яндекс не прислал — генерируем по контенту тела (идемпотентность)
            $session_id = 'auto-' . md5(wp_json_encode($body));
        }

        // Идемпотентность: если сессия уже есть — вернём тот же номер
        $existing = self::find_order_by_session($session_id);
        if ($existing) {
            self::respond(201, ['order_number' => (string) $existing->get_id()]);
        }

        // Создаём в draft-статусе, чтобы письмо «Новый заказ» НЕ ушло пустым
        $wc_order = wc_create_order(['status' => 'checkout-draft']);
        if (is_wp_error($wc_order)) self::respond(500, ['error' => 'cannot create order']);

        // Товары
        foreach (($body['items'] ?? []) as $item) {
            $pid = self::resolve_product_id((string)($item['id'] ?? ''));
            $p   = $pid ? wc_get_product($pid) : null;
            if (!$p) continue;
            $qty = (int)($item['quantity'] ?? 1);
            $wc_item_id = $wc_order->add_product($p, $qty);
            // Зафиксируем цены, присланные Яндексом
            if ($wc_item_id && isset($item['final_price'])) {
                $wc_item = $wc_order->get_item($wc_item_id);
                if ($wc_item) {
                    $wc_item->set_subtotal((float)$item['regular_price'] * $qty);
                    $wc_item->set_total((float)$item['final_price'] * $qty);
                    $wc_item->save();
                }
            }
        }

        // Покупатель
        $cust = $body['customer'] ?? [];
        $name = trim((string)($cust['full_name'] ?? $cust['name'] ?? ''));
        $parts = explode(' ', $name, 2);
        $wc_order->set_billing_first_name($parts[0] ?? '');
        $wc_order->set_billing_last_name($parts[1] ?? '');
        $wc_order->set_billing_email((string)($cust['email'] ?? ''));
        $wc_order->set_billing_phone((string)($cust['phone'] ?? ''));

        // Доставка
        $delivery = $body['delivery'] ?? [];
        $addr     = $delivery['address'] ?? [];
        if ($addr) {
            $wc_order->set_billing_city((string)($addr['locality'] ?? ''));
            $wc_order->set_billing_address_1((string)($addr['address'] ?? ''));
            $wc_order->set_shipping_city((string)($addr['locality'] ?? ''));
            $wc_order->set_shipping_address_1((string)($addr['address'] ?? ''));
        }
        $wc_order->set_billing_country('RU');
        $wc_order->set_shipping_country('RU');

        $wc_order->set_payment_method('ycp');
        $wc_order->set_payment_method_title('Яндекс (YCP)');

        $wc_order->update_meta_data('_ycp_session_id', $session_id);
        $wc_order->update_meta_data('_ycp_warehouse_id', (string)($body['warehouse_id'] ?? 'main'));
        $wc_order->update_meta_data('_ycp_delivery_method', (string)($delivery['delivery_method'] ?? ''));
        $wc_order->update_meta_data('_ycp_raw', wp_json_encode($body, JSON_UNESCAPED_UNICODE));

        $wc_order->calculate_totals();
        $wc_order->save();
        // Теперь, когда товары/адрес/итого на месте — переводим в pending.
        // Это триггер для письма «Новый заказ» с КОРРЕКТНОЙ суммой.
        $wc_order->update_status('pending', 'YCP: оформление заказа');

        self::respond(201, ['order_number' => (string) $wc_order->get_id()]);
    }

    // ============================================================
    // POST /checkout/placed — заказ оформлен
    // ============================================================
    private static function handle_checkout_placed($body): void
    {
        $session_id = (string)($body['session_id'] ?? '');
        $order_id   = (string)($body['order_id'] ?? '');
        $pay_method = (string)($body['payment_method'] ?? 'online');

        $order = self::find_order_by_session($session_id);
        if (!$order) self::respond(404, ['error' => 'session not found']);

        if ($order->get_status() === 'cancelled') {
            self::respond(409, ['error' => 'order already cancelled']);
        }

        $order->update_meta_data('_ycp_order_id', $order_id);
        $order->update_meta_data('_ycp_payment_method', $pay_method);
        if ($pay_method === 'online') {
            $order->payment_complete(); // → processing/completed
        } else {
            $order->update_status('processing', 'YCP: оплата при получении');
        }
        $order->save();

        self::respond(200, ['status' => 'ok']);
    }

    // ============================================================
    // POST /checkout/cancel
    // ============================================================
    private static function handle_checkout_cancel($body): void
    {
        $session_id = (string)($body['session_id'] ?? '');
        $order = self::find_order_by_session($session_id);
        if (!$order) self::respond(404, ['error' => 'session not found']);
        $order->update_status('cancelled', 'YCP: сессия отменена');
        self::respond(200, ['status' => 'ok']);
    }

    // ============================================================
    // GET /order?order_id=X
    // ============================================================
    private static function handle_order_get(): void
    {
        $order_id = isset($_GET['order_id']) ? sanitize_text_field(wp_unslash($_GET['order_id'])) : '';
        $order = self::find_order_by_ycp_id($order_id);
        if (!$order) self::respond(404, ['error' => 'order not found']);

        $items = [];
        foreach ($order->get_items() as $it) {
            $p = $it->get_product();
            if (!$p) continue;
            $items[] = [
                'id'             => $p->get_sku() ?: (string)$p->get_id(),
                'quantity'       => (int) $it->get_quantity(),
                'refused_quantity' => 0,
            ];
        }

        $statuses = [['status' => 'new', 'datetime' => $order->get_date_created()->getTimestamp()]];
        $st = $order->get_status();
        if (in_array($st, ['processing','on-hold'], true)) {
            $statuses[] = ['status' => 'in_progress', 'datetime' => time()];
        } elseif ($st === 'completed') {
            $statuses[] = ['status' => 'in_progress', 'datetime' => $order->get_date_modified()->getTimestamp() - 3600];
            $statuses[] = ['status' => 'delivered',   'datetime' => $order->get_date_modified()->getTimestamp()];
        } elseif ($st === 'cancelled') {
            $statuses[] = ['status' => 'cancelled', 'datetime' => $order->get_date_modified()->getTimestamp()];
        }

        self::respond(200, [
            'items'             => $items,
            'delivery_statuses' => $statuses,
        ]);
    }

    // ============================================================
    // POST /order/cancel
    // ============================================================
    private static function handle_order_cancel($body): void
    {
        $order_id = (string)($body['order_id'] ?? '');
        $order = self::find_order_by_ycp_id($order_id);
        if (!$order) self::respond(404, ['error' => 'order not found']);
        $order->update_status('cancelled', 'YCP: заказ отменён Яндексом');
        self::respond(200, ['status' => 'ok']);
    }

    // ============================================================
    // POST /order/delivered
    // ============================================================
    private static function handle_order_delivered($body): void
    {
        $get_id   = isset($_GET['order_id']) ? sanitize_text_field(wp_unslash($_GET['order_id'])) : '';
        $order_id = $get_id !== '' ? $get_id : (string)($body['order_id'] ?? '');
        $order = self::find_order_by_ycp_id($order_id);
        if (!$order) self::respond(404, ['error' => 'order not found']);
        if ($order->get_status() === 'cancelled') {
            self::respond(409, ['error' => 'order cancelled']);
        }
        $purchased = $body['purchased_items'] ?? [];
        $order->update_meta_data('_ycp_purchased_items', wp_json_encode($purchased, JSON_UNESCAPED_UNICODE));
        $order->update_status('completed', 'YCP: доставлен покупателю');
        self::respond(200, ['status' => 'ok']);
    }

    // ============================================================
    // Утилиты
    // ============================================================

    /** Ищем WC-товар по SKU, потом по ID. */
    private static function resolve_product_id(string $id): int
    {
        if ($id === '') return 0;
        $by_sku = wc_get_product_id_by_sku($id);
        if ($by_sku) return (int) $by_sku;
        if (ctype_digit($id)) {
            $p = wc_get_product((int)$id);
            if ($p) return (int)$id;
        }
        return 0;
    }

    private static function find_order_by_session(string $session_id)
    {
        if (!$session_id) return null;
        $orders = wc_get_orders([
            'limit'      => 1,
            'status'     => 'any',
            'meta_query' => [['key' => '_ycp_session_id', 'value' => $session_id]],
        ]);
        return $orders[0] ?? null;
    }

    private static function find_order_by_ycp_id(string $id)
    {
        if (!$id) return null;
        if (ctype_digit($id)) {
            $o = wc_get_order((int)$id);
            if ($o) return $o;
        }
        $orders = wc_get_orders([
            'limit'      => 1,
            'status'     => 'any',
            'meta_query' => [['key' => '_ycp_order_id', 'value' => $id]],
        ]);
        return $orders[0] ?? null;
    }

    /**
     * Возвращает JSON-ответ и завершает запрос.
     */
    private static function respond(int $code, array $data): void
    {
        if (!headers_sent()) {
            status_header($code);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function log_entry(string $method, string $action, string $token_masked, string $body): void
    {
        $log = (array) get_option(self::OPT_LOG, []);
        $log[] = [
            'time'   => current_time('Y-m-d H:i:s'),
            'method' => $method,
            'action' => $action ?: 'ping',
            'token'  => $token_masked,
            'body'   => mb_substr($body, 0, 4000, 'UTF-8'),
            'ip'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ];
        if (count($log) > 100) $log = array_slice($log, -100);
        update_option(self::OPT_LOG, $log, false);
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'perfinn-ycp-checkout'));
        $token = (string) get_option(self::OPT_TOKEN, '');
        $endpoint_url = home_url('/' . self::ENDPOINT . '/');
        $log = (array) get_option(self::OPT_LOG, []);
        ?>
        <div class="wrap">
            <h1>YCP Yandex</h1>

            <h2>Настройка</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ycpy'); ?>
                <table class="form-table">
                    <tr>
                        <th>Bearer-токен от Яндекса</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPT_TOKEN); ?>"
                                value="<?php echo esc_attr($token); ?>"
                                class="regular-text" style="width:680px;font-family:monospace;" />
                            <p class="description">Скопируйте «Ваш токен API» из кабинета Яндекса в это поле.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL для Яндекса</th>
                        <td>
                            <code style="font-size:14px;background:#fef2f2;padding:8px 12px;display:inline-block;border-radius:4px;">
                                <?php echo esc_html($endpoint_url); ?>
                            </code>
                            <p class="description">Этот URL вставьте в поле «URL для API» в кабинете Яндекса.</p>
                        </td>
                    </tr>
                </table>

                <h2>Склад магазина</h2>
                <p class="description">Эти данные отдаются Яндексу при запросе GET /api/v1/warehouses.</p>
                <table class="form-table">
                    <tr><th>Название склада</th><td><input type="text" name="ycpy_wh_title" value="<?php echo esc_attr(get_option('ycpy_wh_title', get_bloginfo('name') . ' — основной склад')); ?>" class="regular-text" style="width:680px;"/></td></tr>
                    <tr><th>Адрес склада</th><td><input type="text" name="ycpy_wh_address" value="<?php echo esc_attr(get_option('ycpy_wh_address', '')); ?>" class="regular-text" style="width:680px;"/></td></tr>
                    <tr><th>Телефон</th><td><input type="text" name="ycpy_wh_phone" value="<?php echo esc_attr(get_option('ycpy_wh_phone', '')); ?>" class="regular-text"/></td></tr>
                    <tr><th>Описание</th><td><input type="text" name="ycpy_wh_description" value="<?php echo esc_attr(get_option('ycpy_wh_description', '')); ?>" class="regular-text" style="width:680px;"/></td></tr>
                    <tr><th>Самовывоз доступен?</th><td><label><input type="checkbox" name="ycpy_wh_self_pickup" value="1" <?php checked(get_option('ycpy_wh_self_pickup'), '1'); ?>/> Покупатель может забрать заказ из этого склада</label></td></tr>
                </table>
                <?php submit_button('Сохранить токен'); ?>
            </form>

            <h2>Проверка соединения вручную</h2>
            <p>После сохранения токена нажмите в Яндексе «Проверить подключение». Либо проверьте локально:</p>
            <pre style="background:#f4f4f5;padding:14px;border-radius:6px;overflow:auto;">curl -H "Authorization: Bearer <?php echo esc_html(substr($token, 0, 10) . '...'); ?>" <?php echo esc_html($endpoint_url); ?></pre>

            <h2>Поддержка</h2>
            <p>По техническим вопросам, ошибкам и доработкам пишите на <a href="mailto:info@perfinn.ru">info@perfinn.ru</a>.</p>

            <h2>Лог последних запросов</h2>
            <table class="widefat striped">
                <thead><tr><th>Время</th><th>Метод</th><th>Action</th><th>IP</th><th>Токен</th><th>Тело</th></tr></thead>
                <tbody>
                <?php foreach (array_slice(array_reverse($log), 0, 30) as $e): ?>
                    <tr>
                        <td><?php echo esc_html($e['time'] ?? '—'); ?></td>
                        <td><?php echo esc_html($e['method'] ?? '—'); ?></td>
                        <td><?php echo esc_html($e['action'] ?? '—'); ?></td>
                        <td><?php echo esc_html($e['ip'] ?? '—'); ?></td>
                        <td><?php echo esc_html($e['token'] ?? '—'); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html(mb_substr((string)($e['body'] ?? ''), 0, 200, 'UTF-8')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

YCP_Yandex_Commerce_Woo::init();
