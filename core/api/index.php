<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once dirname(__FILE__) . "/../php/core.inc.php";

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

$configuration = [
    'settings' => [
        'displayErrorDetails' => true,
    ],
];
$c = new \Slim\Container($configuration);

$app = new \Slim\App($c);

/*******************************
 * Verification of the apiKey  *
 *******************************/
$app->add(function ($request, $response, $next) {
    // ping can be accessed without apiKey
    $path = $request->getUri()->getPath();
    if (strstr($path, "ping")) {
        return $next($request, $response);
    }
    $apiKey = $request->getHeaderLine('apiKey');

    if (!isset($apiKey) || !jeedom::apiAccess($apiKey)) {
        connection::failed();
        $response = $response->withStatus(401, "You are not authorized to access the api without an apiKey or a wrong apiKey");
        return $response;
    } else {
        connection::success('api');
        return $next($request, $response);
    }
});

/*******************************
 *      global methods         *
 *******************************/

$app->get('/ping', function (Request $request, Response $response) {
    $response->getBody()->write("pong");
    return $response;
});

$app->get('/version', function (Request $request, Response $response) {
    $response->getBody()->write(jeedom::version());
    return $response;
});

$app->get('/datetime', function (Request $request, Response $response) {
    $response->getBody()->write(getmicrotime());
    return $response;
});

/*******************************
 *          objects            *
 *******************************/
// TODO : move in object.class.php
function getFullObject($object) {
    $object_return = utils::o2a($object);

    $object_return['eqLogics'] = array();
    foreach ($object->getEqLogic(true, true) as $eqLogic) {
        $eqLogic_return = utils::o2a($eqLogic);
        $eqLogic_return['cmds'] = array();
        foreach ($eqLogic->getCmd() as $cmd) {
            $cmd_return = utils::o2a($cmd);
            if ($cmd->getType() == 'info') {
                $cmd_return['state'] = $cmd->execCmd();
            }
            $eqLogic_return['cmds'][] = $cmd_return;
        }
        $object_return['eqLogics'][] = $eqLogic_return;
    }
    return $object_return;
}

$app->group('/objects', function () use ($app) {
    $app->get('', function ($request, $response) {
        parse_str($request->getUri()->getQuery(), $params);

        $onlyVisible = ($params['onlyVisible'] === "true" || $params['onlyVisible'] === "");

        if (isset($params['full'])) {
            $result = array();
            foreach (object::all($onlyVisible) as $object) {
                $object_return = getFullObject($object);
                $result[] = $object_return;
            }
        } else {
            $result = utils::o2a(object::all($onlyVisible));
        }
        $response->getBody()->write(json_encode($result));
        return $response;
    });

    $app->get('/{id:\d+}', function ($request, $response, $args) {
        $object = object::byId($args['id']);
        if (!is_object($object)) {
            $response = $response->withStatus(404, "Object '" . $args['id'] . "' does not exist");
        } else {
            parse_str($request->getUri()->getQuery(), $params);
            $return = utils::o2a($object);
            if (isset($params['full'])) {
                $return = getFullObject($object);
            }
            $response->getBody()->write(json_encode($return));
        }
        return $response;
    });
});

$app->run();
