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




function chamilo_rest_api($body){
    $chamilo_url = get_option('chamilo_setting_url'); // Chamilo API Base URL
    $admin_user = get_option('chamilo_setting_admin'); // Chamilo Administrator
    $api_key = get_option('chamilo_setting_key'); // Chamilo API Key
    
    if (empty($chamilo_url) || empty($admin_user) || empty($api_key)) {
        return new WP_Error('missing_config', 'Chamilo API 配置缺失', ['status' => 400]);
    }

    $api_endpoint = rtrim($chamilo_url, '/') . '/main/webservices/api/v2.php'; // ensure URL not end `/`
    
    $request_body = [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query(array_merge(
            $body,
            [
                'username' => $admin_user,
                'api_key' => $api_key,
            ]
        )),
    ];
    // send POST request
    $response = wp_remote_post($api_endpoint, $request_body);

    // handle error
    if (is_wp_error($response)) {
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    error_log('body: ' . $body);
    $data = json_decode($body,true);
    if (empty($data)) {
        return new WP_Error('api_error', 'Chamilo API error', ['status' => 500, 'response' => $body]);
    }
    return $data;
    
}

function chamilo_get_course_description($course){
    $request_body = [
        'action'   => 'course_descriptions',
        'course' => $course,
    ];
    return chamilo_rest_api($request_body);
}


function chamilo_get_courses_rest_api(){
    $request_body = [
            'action'   => 'get_courses',
    ];
    $data = chamilo_rest_api($request_body);

    if (is_wp_error($data)) {
        error_log('Received WP_Error: ' . $data->get_error_message());
        return $data;
    } elseif (empty($data)) {
        error_log('Empty data or contains error key: ' . print_r($data, true));
        return new WP_Error('api_error', 'Chamilo API error', ['status' => 500, 'response' => $data]);
    }

    // format data
    $courses_list = [];
    foreach ($data['data'] as $course) {
        $request_pic = [
            'action'   => 'course_info',
            'course' => $course['id']
        ];
        $response_course_info = chamilo_rest_api($request_pic);


        $courses_list[] = [
            'code' => $course['id'] ?? '',
            'title' => $course['title'] ?? '',
            'url_picture' => $response_course_info['data']['urlPicture'] ?? '',
            'language' => $course['course_language'] ?? 'unknown',
            'teachers' => $response_course_info['data']['teachers'] ?? 'unknown',
            'about_url' => $chamilo_url . '/course/' . $course['id'] .  '/about'
        ];
    }

    return $courses_list;
}

function display_course_info(){
    $courses_list = chamilo_get_courses_rest_api();
    $chamilo_url = get_option('chamilo_setting_url'); // Chamilo API Base URL
    $default_pic = rtrim($chamilo_url, '/') . '/main/img/session_default.png'; // ensure URL not end `/`
    if (is_wp_error($courses_list)) {
        return '<p>Can\'t get courses: ' . $response->get_error_message() . '</p>';
    }

    $output = '<div style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between;">';

    foreach ($courses_list as $item) {
     

        $code = isset($item['code']) ? $item['code'] : '';
        $title = isset($item['title']) ? $item['title'] : 'No Title';
        $url_picture = isset($item['url_picture']) ? $item['url_picture'] : '';
        $language = isset($item['language']) ? $item['language'] : 'nuknow';
        $about_url = isset($item['about_url']) ? $item['about_url'] : '#';

        $teachers = isset($item['teachers']) ? $item['teachers'] : 'nuknow';

        $output .= '<a href="' . esc_url($about_url) . '" style="flex: 0 0 calc(25% - 15px); text-decoration: none; color: inherit;" target="_blank">';
        $output .= '<div style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); box-sizing: border-box; height: 100%; display: flex; flex-direction: column;">';
        
        // picture
        if ($url_picture) {
            $output .= '<div style="width: 100%; height: 150px; overflow: hidden; border-radius: 4px; margin-bottom: 10px;">';
            $output .= '<img src="' . esc_url($url_picture) . '" alt="' . esc_attr($title) . '" style="width: 100%; height: 100%; object-fit: cover;">';
            $output .= '</div>';
        }else{
            $output .= '<div style="width: 100%; height: 150px; overflow: hidden; border-radius: 4px; margin-bottom: 10px;">';
            $output .= '<img src="' . esc_url($default_pic) . '" alt="' . esc_attr($title) . '" style="width: 100%; height: 100%; object-fit: cover;">';
            $output .= '</div>';
        }

        // text
        $output .= '<div style="flex-grow: 1;">';
        $output .= '<h3 style="margin: 0 0 10px 0; font-size: 18px;">' . esc_html($title) . '</h3>';
        $output .= '<p style="margin: 5px 0; color: #666;">Language: ' . esc_html($language) . '</p>';
        $output .= '<p style="margin: 5px 0; color: #666;">Teachers: ' . esc_html($teachers) . '</p>';
        $output .= '</div>';

        $output .= '</div>';
        $output .= '</a>';
    }

    $output .= '</div>';

    return $output;
    
}