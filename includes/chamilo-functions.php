<?php
/**
 * Define a few constants
 */
define('CHAMILO_WP_PUBLIC_IP', '');
define('CHAMILO_SECRET_KEY', 1);
define('CHAMILO_PERUSER_SIGNATURE', 2);
define('CHAMILO_GLOBAL_SIGNATURE', 3);
/**
 * Basic install/uninstall functions
 */
function chamilo_install() {
    // Código de instalación
    register_setting( 'reading', 'chamilo_setting_url' );
    register_setting( 'reading', 'chamilo_setting_key' );
}
function chamilo_deactivation() {
    // Código de desactivación
}
function chamilo_uninstall() {
    // Código de desinstalación
    unregister_setting( 'chamilo_setting_url' );
    unregister_setting( 'chamilo_setting_key' );
}

/**
 * Basic settings functions
 */
function chamilo_connectivity_section_callback() {
    echo '<p>' . __( 'Please configure your global Chamilo connectivity settings', 'chamilo' ) . '</p>';
}

function chamilo_setting_url_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_url' ) );
    echo "<input type='text' name='chamilo_setting_url' value='$setting' />";
}

function chamilo_setting_admin_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_admin' ) );
    echo "<input type='text' name='chamilo_setting_admin' value='$setting' />";
}

function chamilo_setting_key_callback_function() {
    $setting = esc_attr( get_option( 'chamilo_setting_key' ) );
    echo "<input type='text' name='chamilo_setting_key' value='$setting' />";
}

function chamilo_settings_api_init() {
    add_settings_section(
        'chamilo_connectivity_section',
        __( 'Chamilo connectivity', 'chamilo' ),
        'chamilo_connectivity_section_callback',
        'reading'
    );
    add_settings_field(
        'chamilo_setting_url',
        __( 'Chamilo\'s portal url', 'chamilo' ),
        'chamilo_setting_url_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_url');
    add_settings_field(
        'chamilo_setting_admin',
        __( 'Chamilo\'s admin username', 'chamilo' ),
        'chamilo_setting_admin_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_admin');
    add_settings_field(
        'chamilo_setting_key',
        __( 'Chamilo\'s api key', 'chamilo' ),
        'chamilo_setting_key_callback_function',
        'reading',
        'chamilo_connectivity_section'
    );
    register_setting('reading', 'chamilo_setting_key');
}

/**
 * Basic menu functions
 */

/**
 * Get data from Chamilo
 */
function chamilo_get_courses($visibilities = array()) {
    $signature = chamilo_get_signature(CHAMILO_GLOBAL_SIGNATURE);
    $username = get_option('chamilo_setting_admin');
    if (empty($visibilites)) {
        $visibilities = 'public,public-registered';
    }
    $courses = chamilo_soap_call( 'courses_list', 'WSCourseList', 'admin', $signature, $visibilities );
    return $courses;
}




function chamilo_rest_api($body){
    $chamilo_url = get_option('chamilo_setting_url'); // Chamilo API 基础 URL
    $admin_user = get_option('chamilo_setting_admin'); // Chamilo 管理员用户名
    $api_key = get_option('chamilo_setting_key'); // Chamilo API Key

    if (empty($chamilo_url) || empty($admin_user) || empty($api_key)) {
        return new WP_Error('missing_config', 'Chamilo API 配置缺失', ['status' => 400]);
    }

    $api_endpoint = rtrim($chamilo_url, '/') . '/main/webservices/api/v2.php'; // 确保 URL 末尾无 `/`
    
    $request_body = [
        'headers' => [
            'Content-Type' => 'multipart/form-data',
        ],
        'body' => array_merge(
            $body,
            [
                'username' => $admin_user,
                'api_key' => $api_key,
            ]
        ),
    ];
    // 发送 POST 请求
    $response = wp_remote_post($api_endpoint, $request_body);

    // 错误处理
    if (is_wp_error($response)) {
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data) || isset($data['error'])) {
        return new WP_Error('api_error', 'Chamilo API 返回错误', ['status' => 500, 'response' => $body]);
    }
    return $data;
}

function chamilo_get_course_description($course){
    $request_body = [
        'action'   => 'course_descriptions',
        'course' => $course,
    ];
    return chamilo_rest_api(request_body);
}






function chamilo_get_courses_rest_api(){
    $request_body = [
            'action'   => 'get_courses',
    ];
    $data = chamilo_rest_api(request_body);

    if (empty($data) || isset($data['error'])) {
        return new WP_Error('api_error', 'Chamilo API 返回错误', ['status' => 500, 'response' => $body]);
    }

    // 格式化返回数据
    $courses_list = [];
    foreach ($data as $course) {
        $request_pic = [
            'action'   => 'course_info',
            'course' => $course['id']
        ];
        $response_course_info = chamilo_rest_api(request_pic);


        $courses_list[] = [
            'code' => $course['id'] ?? '',
            'title' => $course['title'] ?? '',
            'url_picture' => $response_course_info['urlPicture'] ?? '',
            'language' => $course['course_language'] ?? 'unknown',
            'teachers' => $response_course_info['teachers'] ?? 'unknown',
            'about_url' => $chamilo_url . '/course/' . $course['id'] .  '/about'
        ];
    }

    return $courses_list;
}

function chamilo_soap_call() {
    // Prepare params
    $params = func_get_args();
    $service = array_shift($params);
    $action = array_shift($params);
    ini_set('soap.wsdl_cache_enabled', 0);
    $services = array( 'courses_list', 'user_info', 'registration' );
    if ( !in_array( $service, $services ) ) {
        // Asking for rogue service, blocking!
        return false;
    }

    $service_path = get_option('chamilo_setting_url');
    if (substr($service_path, -1, 1) != '/') {
        $service_path .= '/';
    }
    $service_path .= 'main/webservices/' . $service . '.soap.php?wsdl';

    // Init SOAP client
    if (!empty($service_path)) {
        $client = new SoapClient($service_path);
        // Make call and its return result
        try {
            $r = $client->__soapCall($action, $params);
        } catch (Exception $e) {
            error_log('In chamilo_soap_call, exception when calling: '.$e->getMessage());
            return false;
        }
        return $r;
    } else {
        return FALSE;
    }
}

function chamilo_get_signature($type = CHAMILO_SECRET_KEY) {
    global $user;
    
    switch ($type) {
        case CHAMILO_PERUSER_SIGNATURE:
            //chamilo_load_user_data($user);
            //if (isset($user->chamilo_settings)) {
            //    return sha1($user->chamilo_settings['user'] . $user->chamilo_settings['apikey']);
            //}
            return '';
            break;
        case CHAMILO_SECRET_KEY:
            $addr = (CHAMILO_WP_PUBLIC_IP == '' ? $_SERVER['SERVER_ADDR'] : CHAMILO_WP_PUBLIC_IP);
            $chamilo_apikey = sha1( $addr . get_option( 'chamilo_setting_key' ) );
            return $chamilo_apikey;
            break;
        case CHAMILO_GLOBAL_SIGNATURE:
        default:
            $chamilo_user = get_option( 'chamilo_setting_admin' );
            $chamilo_apikey = get_option( 'chamilo_setting_key' );
            return sha1($chamilo_user . $chamilo_apikey);
            return '';
    }
}

function chamilo_get_course_visibilities() {
    return array(
        'public' => __('public', 'chamilo'),
        'private' => __('private', 'chamilo'),
        'public-registered' => __('public registered', 'chamilo'),
        'closed' => __('closed', 'chamilo')
    );
}

/**
 * Add blocks / widgets
 */

function chamilo_register_widgets() {
    register_widget( 'ChamiloCoursesListWidget' );
}

function chamilo_display_courses_list($courses) {
    $output = '';
    if (is_array($courses) && !empty($courses)) {
        $output .= '<ul>';
        foreach ($courses as $course) {
            $output .= '<li><a href="'.$course->url.'" target="_blank">'.utf8_decode($course->title).'</a> ('.$course->language.')</li>';
        }
        $output .= '</ul>';
    }
    echo $output;
}