<?php

declare(strict_types=1);

namespace App\Api;

final class ApiMessageCode
{
    public const API_ENDPOINT_OWNER_INVALID = 'api.endpoint_owner_invalid';
    public const API_ENDPOINT_METHOD_INVALID = 'api.endpoint_method_invalid';
    public const API_ENDPOINT_PATH_INVALID = 'api.endpoint_path_invalid';
    public const API_ENDPOINT_ROUTE_INVALID = 'api.endpoint_route_invalid';
    public const API_ENDPOINT_OPERATION_INVALID = 'api.endpoint_operation_invalid';
    public const API_ENDPOINT_HANDLER_INVALID = 'api.endpoint_handler_invalid';
    public const API_ENDPOINT_TAG_INVALID = 'api.endpoint_tag_invalid';
    public const API_ENDPOINT_SUMMARY_EMPTY = 'api.endpoint_summary_empty';
    public const API_ENDPOINT_SUCCESS_STATUS_INVALID = 'api.endpoint_success_status_invalid';
    public const API_ENDPOINT_NOT_FOUND = 'api.endpoint_not_found';
    public const API_REQUEST_INVALID = 'api.request_invalid';
    public const API_VALIDATION_FAILED = 'api.validation_failed';
    public const API_OPERATION_NOT_IMPLEMENTED = 'api.operation_not_implemented';
    public const API_OPERATION_UNAVAILABLE = 'api.operation_unavailable';
    public const API_UNAVAILABLE_SETUP_INCOMPLETE = 'api.unavailable_setup_incomplete';
    public const API_UNAVAILABLE_DATABASE = 'api.unavailable_database';
    public const API_UNAVAILABLE_MAINTENANCE = 'api.unavailable_maintenance';
    public const API_UNAVAILABLE_DISABLED = 'api.unavailable_disabled';
}
