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
    public const API_ENDPOINT_SUMMARY_EMPTY = 'api.endpoint_summary_empty';
    public const API_ENDPOINT_SUCCESS_STATUS_INVALID = 'api.endpoint_success_status_invalid';
    public const API_UNAVAILABLE_SETUP_INCOMPLETE = 'api.unavailable_setup_incomplete';
    public const API_UNAVAILABLE_DATABASE = 'api.unavailable_database';
    public const API_UNAVAILABLE_MAINTENANCE = 'api.unavailable_maintenance';
}
