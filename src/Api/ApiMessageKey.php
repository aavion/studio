<?php

declare(strict_types=1);

namespace App\Api;

final class ApiMessageKey
{
    public const API_ENDPOINT_OWNER_INVALID = 'message.api.endpoint.owner_invalid';
    public const API_ENDPOINT_METHOD_INVALID = 'message.api.endpoint.method_invalid';
    public const API_ENDPOINT_PATH_INVALID = 'message.api.endpoint.path_invalid';
    public const API_ENDPOINT_ROUTE_INVALID = 'message.api.endpoint.route_invalid';
    public const API_ENDPOINT_OPERATION_INVALID = 'message.api.endpoint.operation_invalid';
    public const API_ENDPOINT_SUMMARY_EMPTY = 'message.api.endpoint.summary_empty';
    public const API_ENDPOINT_SUCCESS_STATUS_INVALID = 'message.api.endpoint.success_status_invalid';
    public const API_UNAVAILABLE_SETUP_INCOMPLETE = 'message.api.unavailable.setup_incomplete';
    public const API_UNAVAILABLE_DATABASE = 'message.api.unavailable.database';
    public const API_UNAVAILABLE_MAINTENANCE = 'message.api.unavailable.maintenance';
}
