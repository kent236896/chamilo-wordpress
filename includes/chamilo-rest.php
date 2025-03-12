<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register REST API endpotion
 */
function chamilo_register_rest_routes() {
    register_rest_route('chamilo/v1', '/courses', [
        'methods'  => 'GET',
        'callback' => 'chamilo_get_courses_rest',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('chamilo/v1', '/courses/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'chamilo_get_course_detail',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * get all courses
 */
function chamilo_get_courses_rest() {
    return chamilo_get_courses_rest_api();
}

/**
 * get course description
 */
function chamilo_get_course_detail($request) {
    $id = $request['id'];
    return chamilo_get_course_description($id);
    
    return new WP_Error('not_found', 'Course not found', ['status' => 404]);
}