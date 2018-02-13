<?php namespace sasco\LibreDTE\SDK; ?>
<?php $this->load->view("partial/header"); ?>
<script type='text/javascript' src="bower_components/dist/print.min.js"></script>
	<script>
		$(document).ready(function(){
	    	$("button").click(function(){
	        printJS("<?php base_url(); ?>receipt.pdf");
	    });
	    });
	</script>

<?php
class LibreDTE
{

    private $Rest; ///< Objeto para manejar las conexiones REST
    private $url; ///< Host con la dirección web base de LibreDTE
    private $header = []; ///< Valores a pasar de la cabecera a curl
    private $sslv3 = false; ///< Indica si la versión de SSL es la 3 en el servidor
    private $sslcheck = true; ///< Indica si se debe validar el certificado SSL del servidor

 
    public function __construct($hash, $url = 'https://libredte.cl')
    {
        $this->url = $url;
        $this->Rest = new \sasco\LibreDTE\SDK\Network\Http\Rest();
        $this->Rest->setAuth($hash);
    }
    public function setHeader($header = [])
    {
        $this->header = $header;
    }
    public function setSSL($sslv3 = false, $sslcheck = true)
    {
        $this->sslv3 = $sslv3;
        $this->sslcheck = $sslcheck;
    }
    public function post($api, $data = null)
    {
        return $this->Rest->post($this->url.'/api'.$api, $data, $this->header, $this->sslv3, $this->sslcheck);
    }
    public function get($api, $data = null)
    {
        return $this->Rest->get($this->url.'/api'.$api, $data, $this->header, $this->sslv3, $this->sslcheck);
    }

}?>
<?php

namespace sasco\LibreDTE\SDK\Network\Http;

class Socket
{

    protected static $methods = ['get', 'put', 'patch', 'delete', 'post']; ///< Métodos HTTP soportados
    protected static $header = [
        'User-Agent' => 'SowerPHP Network_Http_Socket',
        //'Content-Type' => 'application/x-www-form-urlencoded',
    ]; ///< Cabeceras por defecto
    protected static $errors = []; ///< Arrglo para errores de cURL
    public static function __callStatic($method, $args)
    {
        if (!isset($args[0]) or !in_array($method, self::$methods))
            return false;
        $method = strtoupper($method);
        $url = $args[0];
        $data = isset($args[1]) ? $args[1] : [];
        $header = isset($args[2]) ? $args[2] : [];
        $sslv3 = isset($args[3]) ? $args[3] : false;
        $sslcheck = isset($args[4]) ? $args[4] : true;
        // inicializar curl
        $curl = curl_init();
        // asignar método y datos dependiendo de si es GET u otro método
        if ($method=='GET') {
            if (is_array($data))
                $data = http_build_query($data);
            if ($data) $url = sprintf("%s?%s", $url, $data);
        } else {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        // asignar cabecera
        $header = array_merge(self::$header, $header);
        foreach ($header as $key => &$value) {
            $value = $key.': '.$value;
        }
        // asignar cabecera
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        // realizar consulta a curl recuperando cabecera y cuerpo
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $sslcheck);
        if ($sslv3) {
            curl_setopt($curl, CURLOPT_SSLVERSION, 3);
        }
        $response = curl_exec($curl);
        if (!$response) {
            self::$errors[] = curl_error($curl);
            return false;
        }
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        // cerrar conexión de curl y entregar respuesta de la solicitud
        $header = self::parseHeader(substr($response, 0, $header_size));
        curl_close($curl);
        return [
            'status' => self::parseStatus($header[0]),
            'header' => $header,
            'body' => substr($response, $header_size),
        ];
    }
    private static function parseHeader($header)
    {
        $headers = [];
        $lineas = explode("\n", $header);
        foreach ($lineas as &$linea) {
            $linea = trim($linea);
            if (!isset($linea[0])) continue;
            if (strpos($linea, ':')) {
                list($key, $value) = explode(':', $linea, 2);
            } else {
                $key = 0;
                $value = $linea;
            }
            $key = trim($key);
            $value = trim($value);
            if (!isset($headers[$key])) {
                $headers[$key] = $value;
            } else if (!is_array($headers[$key])) {
                $aux = $headers[$key];
                $headers[$key] = [$aux, $value];
            } else {
                $headers[$key][] = $value;
            }
        }
        return $headers;
    }
    private static function parseStatus($response_line)
    {
        if (is_array($response_line)) {
            $response_line = $response_line[count($response_line)-1];
        }
        list($protocol, $status, $message) = explode(' ', $response_line, 3);
        return [
            'protocol' => $protocol,
            'code' => $status,
            'message' => $message,
        ];
    }
    public static function getErrors()
    {
        return self::$errors;
    }
    public static function getLastError()
    {
        return self::$errors[count(self::$errors)-1];
    }

}
?>
<?php
namespace sasco\LibreDTE\SDK\Network\Http;

/**
 * Clase para un cliente de APIs REST
 * Permite manejar solicitudes y respuestas
 * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
 * @version 2016-01-15
 */
class Rest
{

    protected $methods = ['get', 'put', 'patch', 'delete', 'post']; ///< Métodos HTTP soportados
    protected $config; ///< Configuración para el cliente REST
    protected $header; ///< Cabecerá que se enviará
    protected $errors = []; ///< Errores de la consulta REST

    /**
     * Constructor del cliente REST
     * @param config Arreglo con la configuración del cliente
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-12-02
     */
    public function __construct($config = [])
    {
        // cargar configuración de la solicitud que se hará
        if (!is_array($config))
            $config = ['base'=>$config];
        $this->config = array_merge([
            'base' => '',
            'user' => null,
            'pass' => 'X',
        ], $config);
        // crear cabecera para la solicitud que se hará
        $this->header['User-Agent'] = 'SowerPHP Network_Http_Rest';
        $this->header['Content-Type'] = 'application/json';
        if ($this->config['user']!==null) {
            $this->header['Authorization'] = 'Basic '.base64_encode(
                $this->config['user'].':'.$this->config['pass']
            );
        }
    }

    /**
     * Método que asigna la autenticación para la API REST
     * @param user Usuario (o token) con el que se está autenticando
     * @param pass Contraseña con que se está autenticando (se omite si se usa token)
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-12-02
     */
    public function setAuth($user, $pass = 'X')
    {
        $this->config['user'] = $user;
        $this->config['pass'] = $pass;
        $this->header['Authorization'] = 'Basic '.base64_encode(
            $this->config['user'].':'.$this->config['pass']
        );
    }

    /**
     * Método para realizar solicitud al recurso de la API
     * @param method Nombre del método que se está ejecutando
     * @param args Argumentos para el método de \sasco\LibreDTE\SDK\Network\Http\Socket
     * @return Arreglo con la respuesta HTTP (índices: status, header y body)
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2016-06-04
     */
    public function __call($method, $args)
    {
        if (!isset($args[0]) or !in_array($method, $this->methods))
            return false;
        $resource = $args[0];
        $data = isset($args[1]) ? $args[1] : [];
        $header = isset($args[2]) ? $args[2] : [];
        $sslv3 = isset($args[3]) ? $args[3] : false;
        $sslcheck = isset($args[4]) ? $args[4] : true;
        if ($data and $method!='get') {
            if (isset($data['@files'])) {
                $files = $data['@files'];
                unset($data['@files']);
                $data = ['@data' => json_encode($data)];
                foreach ($files as $key => $file)
                    $data[$key] = $file;
            } else {
                $data = json_encode($data);
                $header['Content-Length'] = strlen($data);
            }
        }
        $response = Socket::$method(
            $this->config['base'].$resource,
            $data,
            array_merge($this->header, $header),
            $sslv3,
            $sslcheck
        );
        if ($response === false) {
            $this->errors[] = Socket::getLastError();
            return false;
        }
        $body = json_decode($response['body'], true);
        return [
            'status' => $response['status'],
            'header' => $response['header'],
            'body' => $body!==null ? $body : $response['body'],
        ];
    }

    /**
     * Método que entrega los errores ocurridos al ejecutar la consulta a REST
     * @return Arreglo con los errores
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2016-01-15
     */
    public function getErrors()
    {
        return $this->errors;
    }

}
?>
<?php
			$url 	= 'https://coranto.cl/libredte';
			$hash 	= 'gUW5nTgcy4CsmuhHDNPXvafSwjGVKz4S';
			$dte = [
			    'Encabezado' => [
			        'IdDoc' => [
			            'TipoDTE' => 39, //static, always the same
			        ],
			        'Emisor' => [
			            'RUTEmisor' => '76643392-8', //company id, like vat number
			         ],
        'Receptor' => [
            'RUTRecep' => '66666666-6',
            'RznSocRecep' => 'Persona sin RUT',
            'GiroRecep' => 'Particular',
            'DirRecep' => 'Santiago',
            'CmnaRecep' => 'Santiago',
        ],
    ],
    'Detalle' => array()
];
foreach ($cart as $line => $item) {
    $dte['Detalle'][]=array(
        'NmbItem' => $item['name'],
        'QtyItem' => $item['quantity'],
        'PrcItem' => $item['price'],
    );
}


			// incluir autocarga de composer
			//require('../vendor/autoload.php');

			// crear cliente
			$LibreDTE = new \sasco\LibreDTE\SDK\LibreDTE($hash, $url);
			// $LibreDTE->setSSL(false, false); ///< segundo parámetro =false desactiva verificación de SSL

			// crear DTE temporal
			$emitir = $LibreDTE->post('/dte/documentos/emitir', $dte);
			if ($emitir['status']['code']!=200) {
			    die('Error al emitir DTE temporal: '.$emitir['body']."\n");
			}

			// crear DTE real
			$generar = $LibreDTE->post('/dte/documentos/generar', $emitir['body']);
			if ($generar['status']['code']!=200) {
			    die('Error al generar DTE real: '.$generar['body']."\n");
			}
			
			// obtener el PDF del DTE
			$generar_pdf = $LibreDTE->get('/dte/dte_emitidos/pdf/'.$generar['body']['dte'].'/'.$generar['body']['folio'].'/'.$generar['body']['emisor']);
			if ($generar_pdf['status']['code']!=200) {
			    die('Error al generar PDF del DTE: '.$generar_pdf['body']."\n");
			}
			// guardar PDF en el disco
			file_put_contents(str_replace('.php', '.pdf', basename(__FILE__)), $generar_pdf['body']);
			?>

<?php
if (isset($error_message))
{
	echo "<div class='alert alert-dismissible alert-danger'>".$error_message."</div>";
	exit;
}
?>

<?php if(!empty($customer_email)): ?>
<script type="text/javascript">
$(document).ready(function()
{
	var send_email = function()
	{
		$.get('<?php echo site_url() . "/sales/send_receipt/" . $sale_id_num; ?>',
			function(response)
			{
				$.notify(response.message, { type: response.success ? 'success' : 'danger'} );
			}, 'json'
		);
	};

	$("#show_email_button").click(send_email);

	<?php if(!empty($email_receipt)): ?>
		send_email();
	<?php endif; ?>
});
</script>
<?php endif; ?>

<?php $this->load->view('partial/print_receipt', array('print_after_sale'=>$print_after_sale, 'selected_printer'=>'receipt_printer')); ?>




<div class="print_hide" id="control_buttons" style="text-align:right">
	<button class="btn btn-info btn-sm" id="show_print_button" ><?php echo '<span class="glyphicon glyphicon-print">&nbsp</span>' . $this->lang->line('common_print'); ?>
        
      </button>
	<a href="javascript:printdoc();"><div class="btn btn-info btn-sm", id="show_print_button"><?php echo '<span class="glyphicon glyphicon-print">&nbsp</span>' . $this->lang->line('common_print'); ?></div></a>

	<?php /* this line will allow to print and go back to sales automatically.... echo anchor("sales", '<span class="glyphicon glyphicon-print">&nbsp</span>' . $this->lang->line('common_print'), array('class'=>'btn btn-info btn-sm', 'id'=>'show_print_button', 'onclick'=>'window.print();')); */ ?>
	<?php if(isset($customer_email) && !empty($customer_email)): ?>
		<a href="javascript:void(0);"><div class="btn btn-info btn-sm", id="show_email_button"><?php echo '<span class="glyphicon glyphicon-envelope">&nbsp</span>' . $this->lang->line('sales_send_receipt'); ?></div></a>
	<?php endif; ?>



	
	<?php echo anchor("sales", '<span class="glyphicon glyphicon-shopping-cart">&nbsp</span>' . $this->lang->line('sales_register'), array('class'=>'btn btn-info btn-sm', 'id'=>'show_sales_button')); ?>


	

	<?php echo anchor("sales/manage", '<span class="glyphicon glyphicon-list-alt">&nbsp</span>' . $this->lang->line('sales_takings'), array('class'=>'btn btn-info btn-sm', 'id'=>'show_takings_button')); ?>
	<!-- Button to generate the pdf in SDK Library -->
	
	
</div>


	<?php $this->load->view("sales/" . $this->config->item('receipt_template')); ?>


<?php $this->load->view("partial/footer"); ?>