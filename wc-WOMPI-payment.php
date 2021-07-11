<?php
class wompi_Payment_Gateway extends WC_Payment_Gateway
{
    function __construct()
    {
        global $woocommerce;
        $this->id = "wompi_payment";
        $this->method_title = __("WOMPI - El Salvador", 'wompi-payment');
        $this->method_description = __("WOMPI - El Salvador Payment Gateway Plug-in para WooCommerce", 'wompi-payment');
        $this->title = __("WOMPI - El Salvador", 'wompi-payment');
        $this->icon = apply_filters('woocommerce_wompi_icon', $woocommerce->plugin_url() . '/../wompi-el-salvador/assets/images/wompi.png');
        $this->has_fields = true;
        $this->init_form_fields();
        $this->init_settings();
        foreach ($this->settings as $setting_key => $value)
        {
            $this->$setting_key = $value;
        }

        add_action('admin_notices', array(
            $this,
            'do_ssl_check'
        ));
        add_action('woocommerce_api_wc_gateway_wompi', array(
            $this,
            'validate_wompi_return'
        ));
        add_action('woocommerce_api_wc_webhook_wompi', array(
            $this,
            'validate_wompi_webhook'
        ));

        if (is_admin())
        {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
        }
    }
    public function validate_wompi_webhook()
    {
        global $woocommerce;
        $headers = getallheaders();
        $ValidoPorEndPoint = false;
        $entityBody = @file_get_contents('php://input');
        write_log('entra en el validate_wompi_webhook ************************************ ' . json_encode($headers) . ' ************');

        write_log('entra en el validate_wompi_webhook ************************************ BODY: ' . $entityBody . ' ************');
        $arrayResult = json_decode($entityBody);
        $order_id = $arrayResult->{'EnlacePago'}->{'IdentificadorEnlaceComercio'};
        $customer_order = new WC_Order($order_id);
        write_log('entra en el validate_wompi_webhook ********** ORDER ID: ' . json_encode($order_id) . ' *****');
        $sig = hash_hmac('sha256', $entityBody, $this->client_secret);
        $hash = $headers['Wompi_Hash'];
        write_log('****----- ver entra al undefined hash $idTransaccion: ' . $arrayResult->{'IdTransaccion'} . ' ------****');

        if (!isset($hash))
        {
            write_log('****----- entra al undefined hash ------****');

            $client_id = WC_Settings_API::get_option('client_id');
            $client_secret = WC_Settings_API::get_option('client_secret');
            $postBodyAux = array(
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'audience' => 'wompi_api',
            );
            $responseAux = wp_remote_post('https://id.wompi.sv/connect/token', array(
                'method' => 'POST',
                'body' => http_build_query($postBodyAux) ,
                'timeout' => 90,
                'sslverify' => false,
            ));
            if (is_wp_error($responseAux))
            {
                $error_messageAux = $responseAux->get_error_message();
            }
            else
            {

                $bodyAux = wp_remote_retrieve_body($responseAux);
                $arrayResultAux = json_decode($bodyAux);
                $token = $arrayResultAux->{'access_token'};

                $args = array(
                    'timeout' => '90',
                    'blocking' => true,
                    'headers' => array(
                        "Authorization" => 'Bearer ' . $token,
                        "content-type" => 'application/json'
                    ) ,
                );
                $responseAux = wp_remote_get('https://api.wompi.sv/TransaccionCompra/' . $arrayResult->{'IdTransaccion'}, $args);

                if (is_wp_error($responseAux))
                {
                    $error_messageAux = $responseAux->get_error_message();
                }
                else
                {
                    $bodyAux = wp_remote_retrieve_body($responseAux);
                    $arrayResultAux = json_decode($bodyAux);
                    if (isset($arrayResultAux->{'esAprobada'}))
                    {
                        if ($arrayResultAux->{'esReal'})
                        {
                            $ValidoPorEndPoint = $arrayResultAux->{'esAprobada'};
                        }
                        else
                        {
                            $ValidoPorEndPoint = false;
                        }
                    }
                }
            }
        }

        update_post_meta($order_id, '_wc_order_wompi_Hash', $hash);
        update_post_meta($order_id, '_wc_order_wompi_cadena', $entityBody);
        write_log('entra en el validate_wompi_webhook ********** HASH: ' . $hash . ' *****');

        $vaux = apache_request_headers();
        write_log('apache_request_headers() ********** ' . json_encode($vaux) . ' *****');

        $TotalComerce = method_exists($customer_order, 'get_total') ? $customer_order->get_total() : $customer_order->order_total;
        $TotalWompi = $arrayResult->{'Monto'};
        if ($TotalComerce == $TotalWompi)
        {
            if ($sig == $hash || $ValidoPorEndPoint)
            {
                if ($sig == $hash)
                {
                    write_log('entra en el validate_wompi_webhook ********** HASH VALIDO  *****');
                    update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' valido:');
                    $customer_order->add_order_note(__('wompi pago completado WH.', 'wompi-payment'));

                    $customer_order->payment_complete();
                    update_post_meta($order_id, '_wc_order_wompi_transactionid', $arrayResult->{'IdTransaccion'}, true);
                    $woocommerce
                        ->cart
                        ->empty_cart();
                    header('HTTP/1.1 200 OK');
                }
                else
                {
                    write_log('entra en el validate_wompi_webhook ********** HASH NO VALIDO  *****');
                    write_log('entra en el validate_wompi_webhook ********** HASH Endpoint VALIDO  *****');
                    update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' valido:');
                    $customer_order->add_order_note(__('wompi pago completado EndPoint.', 'wompi-payment'));

                    $customer_order->payment_complete();
                    update_post_meta($order_id, '_wc_order_wompi_transactionid', $arrayResult->{'IdTransaccion'}, true);
                    $woocommerce
                        ->cart
                        ->empty_cart();
                    header('HTTP/1.1 200 OK');
                }

            }
            else
            {
                write_log('entra en el validate_wompi_webhook ********** HASH NO VALIDO WH *****');
                write_log('entra en el validate_wompi_webhook ********** HASH NO VALIDO EndPoint  *****');

                update_post_meta($order_id, '_wc_order_wompi_transactionid', $arrayResult->{'IdTransaccion'}, true);
                update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' No valido:');
                $customer_order->add_order_note(__('wompi hash no valido WH and EndPoint.', 'wompi-payment'));
                header('HTTP/1.1 200 OK');
            }
        }
        else
        {
            write_log('entra en el validate_wompi_webhook ********** Los montos no coinciden *****');

            update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' No valido:');
            $customer_order->add_order_note(__('wompi Los montos no coinciden.', 'wompi-payment'));
            header('HTTP/1.1 200 OK');
        }
    }
    public function validate_wompi_return()
    {
        global $woocommerce;
        $order_id = sanitize_text_field($_GET['identificadorEnlaceComercio']);
        $customer_order = new WC_Order($order_id);
        $idTransaccion = sanitize_text_field($_GET['idTransaccion']);
        $idEnlace = sanitize_text_field($_GET['idEnlace']);
        $monto = sanitize_text_field($_GET['monto']);
        $hash = sanitize_text_field($_GET['hash']);
        $cadena = $order_id . $idTransaccion . $idEnlace . $monto;
        $sig = hash_hmac('sha256', $cadena, $this->client_secret);

        $authcode = get_post_meta($order_id, '_wc_order_wompi_authcode', true);
        $TotalComerce = method_exists($customer_order, 'get_total') ? $customer_order->get_total() : $customer_order->order_total;
        if ($TotalComerce == $monto)
        {

            if ($authcode == null)
            {

                update_post_meta($order_id, '_wc_order_wompi_Hash', $hash);
                update_post_meta($order_id, '_wc_order_wompi_cadena', $cadena);

                if ($sig == $hash)
                {
                    update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' valido:');
                    $customer_order->add_order_note(__('wompi pago completado.', 'wompi-payment'));

                    $customer_order->payment_complete();
                    update_post_meta($order_id, '_wc_order_wompi_transactionid', $idTransaccion, true);
                    $woocommerce
                        ->cart
                        ->empty_cart();
                    wp_redirect(html_entity_decode($customer_order->get_checkout_order_received_url()));
                }
                else
                {
                    update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' No valido:');
                    $customer_order->add_order_note(__('wompi hash no valido.', 'wompi-payment'));
                    home_url();
                }
            }
            else
            {
                wp_redirect(html_entity_decode($customer_order->get_checkout_order_received_url()));
            }
        }
        else
        {
            update_post_meta($order_id, '_wc_order_wompi_StatusHash', $sig . ' No valido:');
            $customer_order->add_order_note(__('wompi los montos no coinciden.', 'wompi-payment'));
            home_url();
        }
    }

    public function init_form_fields()
    {

        $arrayPuntos = array(
            'true' => 'SI',
            'false' => 'NO'
        );
        $array = array(
            'false' => '(No habilitar pago en cuotas)'
        );
        $client_id = WC_Settings_API::get_option('client_id');
        $client_secret = WC_Settings_API::get_option('client_secret');
        $postBody = array(
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'audience' => 'wompi_api',
        );
        $response = wp_remote_post('https://id.wompi.sv/connect/token', array(
            'method' => 'POST',
            'body' => http_build_query($postBody) ,
            'timeout' => 90,
            'sslverify' => false,
        ));
        if (is_wp_error($response))
        {
            $error_message = $response->get_error_message();
        }
        else
        {

            $body = wp_remote_retrieve_body($response);
            $arrayResult = json_decode($body);
            $token = $arrayResult->{'access_token'};

            $args = array(
                'timeout' => '90',
                'blocking' => true,
                'headers' => array(
                    "Authorization" => 'Bearer ' . $token,
                    "content-type" => 'application/json'
                ) ,
            );
            $response = wp_remote_get('https://api.wompi.sv/Aplicativo', $args);
            if (is_wp_error($response))
            {
                $error_message = $response->get_error_message();
            }
            else
            {
                $body = wp_remote_retrieve_body($response);
                $arrayResult = json_decode($body);
                $respuesta = $arrayResult->{'cuotasDisponibles'};
                $padoConPuntos = false;
                foreach ($respuesta as & $valor)
                {
                    $padoConPuntos = true;
                    $array[$valor->{'cantidadCuotas'}] = $valor->{'cantidadCuotas'} . ' Meses (' . $valor->{'tasa'} . '% comisión)';
                }
                if (!$padoConPuntos)
                {
                    $array = array(
                        'false' => '(Primero debes habilitar pago en cuotas en el portal de Wompi)'
                    );
                }
                $respuesta = $arrayResult->{'aplicaPagoConPuntos'};
                if (!$respuesta)
                {
                    $arrayPuntos = array(
                        'false' => '(para habilitar esta opción debes comunicarse con servicio al cliente del Banco)'
                    );
                }
            }
        }

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Activar / Desactivar', 'wompi-payment') ,
                'label' => __('Activar este metodo de pago', 'wompi-payment') ,
                'type' => 'checkbox',
                'default' => 'no',
            ) ,
            'title' => array(
                'title' => __('Título', 'wompi-payment') ,
                'type' => 'text',
                'desc_tip' => __('Título de pago que el cliente verá durante el proceso de pago.', 'wompi-payment') ,
                'default' => __('Tarjeta de crédito con WOMPI', 'wompi-payment') ,
            ) ,
            'description' => array(
                'title' => __('Descripción', 'wompi-payment') ,
                'type' => 'textarea',
                'desc_tip' => __('Descripción de pago que el cliente verá durante el proceso de pago.', 'wompi-payment') ,
                'default' => __('Pague con seguridad usando su tarjeta de crédito.', 'wompi-payment') ,
                'css' => 'max-width:350px;'
            ) ,
            'TextoWompi' => array(
                'title' => __('Título del pago', 'wompi-payment') ,
                'type' => 'text',
                'desc_tip' => __('Título que aparece en la descripcion del pago en wompi.', 'wompi-payment') ,
                'default' => __('Carrito de la Compra', 'wompi-payment') ,
            ) ,
            'client_id' => array(
                'title' => __('App ID', 'wompi-payment') ,
                'type' => 'text',
                'desc_tip' => __('ID de clave de seguridad del panel de control del comerciante.', 'wompi-payment') ,
                'default' => '',
            ) ,
            'client_secret' => array(
                'title' => __('Api Secret', 'wompi-payment') ,
                'type' => 'text',
                'desc_tip' => __('ID de clave de api del panel de control del comerciante.', 'wompi-payment') ,
                'default' => '',
            ) ,
            'api_email' => array(
                'title' => __('Correo para notificar', 'wompi-payment') ,
                'type' => 'text',
                'desc_tip' => __('El correo del comercio donde se notificará los pagos.', 'wompi-payment') ,
                'default' => '',
                'description' => 'Se puede colocar más de un correo separado por comas Ejemplo: correo@gmail.com,correo2@gmail.com'
            ) ,
            'api_notifica' => array(
                'title' => __('Se notificará al cliente?', 'wompi-payment') ,
                'type' => 'select',
                'options' => array(
                    'true' => 'SI',
                    'false' => 'NO'
                ) ,
                'desc_tip' => __('Si se notificará por correo al cliente el pago.', 'wompi-payment') ,
                'default' => 'true'
            ) ,
            'api_edit_monto' => array(
                'title' => __('El monto es editable?', 'wompi-payment') ,
                'type' => 'select',
                'options' => array(
                    'false' => 'NO',
                    'true' => 'SI'
                ) ,
                'desc_tip' => __('Activar en caso de permitir editar el monto de la compra en Wompi Ejemplo: Donaciones', 'wompi-payment') ,
                'default' => 'false'
            ) ,
            'api_permitirTarjetaCreditoDebido' => array(
                'title' => __('Permitir Tarjeta Crédito/Débido', 'wompi-payment') ,
                'type' => 'select',
                'options' => array(
                    'true' => 'SI',
                    'false' => 'NO'
                ) ,
                'desc_tip' => __('Permitir cobrar con tarjeta de Crédito/Débido', 'wompi-payment') ,
                'default' => 'true'
            ) ,
            'api_permitirPagoCuotas' => array(
                'title' => __('Máximo de cuotas:', 'wompi-payment') ,
                'type' => 'select',
                'options' => $array,
                'desc_tip' => __('Permitir cobrar con cuotas', 'wompi-payment') ,
                'default' => 'false'
            ) ,
            'api_permitirPagoConPuntoAgricola' => array(
                'title' => __('Permitir pago con puntos Agrícola', 'wompi-payment') ,
                'type' => 'select',
                'options' => $arrayPuntos,
                'desc_tip' => __('Permitir cobrar con puntos Agrícola', 'wompi-payment') ,
                'default' => 'true'
            ) ,
        );
    }
    public function process_payment($order_id)
    {
        global $woocommerce;
        $customer_order = new WC_Order($order_id);

        $client_id = $this->client_id;
        $client_secret = $this->client_secret;
        $api_permitirPagoCuotas = $this->api_permitirPagoCuotas;
        if ($api_permitirPagoCuotas === undefined)
        {
            $api_permitirPagoCuotas = 'false';
        }
        $postBody = array(
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'audience' => 'wompi_api',
        );

        $response = wp_remote_post('https://id.wompi.sv/connect/token', array(
            'method' => 'POST',
            'body' => http_build_query($postBody) ,
            'timeout' => 90,
            'sslverify' => false,
        ));

        if (is_wp_error($response))
        {
            $error_message = $response->get_error_message();
            echo "error: " . $error_message;
        }
        else
        {
            $body = wp_remote_retrieve_body($response);
            $arrayResult = json_decode($body);
            $token = $arrayResult->{'access_token'};

            $order = wc_get_order($order_id);
            $url_redi = $this->get_return_url($order);
            $configuracion = array(
                "emailsNotificacion" => $this->api_email,
                "esMontoEditable" => $this->api_edit_monto,
                "esCantidadEditable" => $this->api_edit_monto,
                "urlWebhook" => home_url() . '/?wc-api=WC_webhook_Wompi',
                "urlRedirect" => home_url() . '/?wc-api=WC_Gateway_Wompi',
                "api_notifica" => $this->api_edit_monto
            );
            $api_permitirPagoCuotasRR = true;
            $api_NumeroMaxCuotas = '';
            if ($api_permitirPagoCuotas == 'false')
            {
                $api_permitirPagoCuotasRR = false;
            }
            else
            {
                $api_permitirPagoCuotasRR = true;
                $api_NumeroMaxCuotas = $api_permitirPagoCuotas;
            }

            $formaPago = array(
                "permitirTarjetaCreditoDebido" => $this->api_permitirTarjetaCreditoDebido,
                "permitirPagoConPuntoAgricola" => $this->api_permitirPagoConPuntoAgricola,
                "permitirPagoEnCuotasAgricola" => $api_permitirPagoCuotasRR
            );
            $payload_data = array(
                "identificadorEnlaceComercio" => $order_id,
                "monto" => method_exists($customer_order, 'get_total') ? $customer_order->get_total() : $customer_order->order_total,
                "nombreProducto" => $this->TextoWompi,
                "formaPago" => $formaPago,
                "configuracion" => $configuracion
            );
            if ($api_permitirPagoCuotasRR)
            {
                $payload_data['cantidadMaximaCuotas'] = $api_NumeroMaxCuotas;
            }
            $args = array(
                'body' => wp_json_encode($payload_data) ,
                'timeout' => '90',
                'blocking' => true,
                'headers' => array(
                    "Authorization" => 'Bearer ' . $token,
                    "content-type" => 'application/json'
                ) ,
                'stream_context' => stream_context_create(array(
                    'ssl' => array(
                        'ciphers' => 'DEFAULT:!TLSv1.0:!SSLv3'
                    ) ,
                )) ,
            );
            $response = wp_remote_post('https://api.wompi.sv/EnlacePago', $args);
            if (is_wp_error($response))
            {
                $error_message = $response->get_error_message();
                echo "error: " . $error_message;
            }
            else
            {
                $body = wp_remote_retrieve_body($response);
                $arrayResult = json_decode($body);
                $urlEnlace = $arrayResult->{'urlEnlace'};
                return array(
                    'result' => 'success',
                    'redirect' => $urlEnlace
                );
            }
        }
    }
}

add_action('woocommerce_admin_order_data_after_billing_address', 'show_WOMPI_info', 10, 1);
function show_WOMPI_info($order)
{
    $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    echo '<p><strong>' . __('WOMPI Transaction Id') . ':</strong> ' . get_post_meta($order_id, '_wc_order_wompi_transactionid', true) . '</p>';
}

if (!function_exists('write_log'))
        {
            function write_log($log)
            {
                if (true === WP_DEBUG)
                {
                    if (is_array($log) || is_object($log))
                    {
                        error_log(print_r($log, true));
                    }
                    else
                    {
                        error_log($log);
                    }
                }
            }
        }