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

/**
 * @SWG\Swagger(
 *     basePath="/jeedom/core/api",
 *     schemes={"http", "https"},
 *     @SWG\Info(
 *         version="2.1.0",
 *         title="Jeedom REST API",
 *         description="Interact with Jeedom using REST API. The API is accessible from URL_JEEDOM/core/api.",
 *         @SWG\License(name="GPL v3", url="http://www.gnu.org/licenses/gpl-3.0.html")
 *     ),
 *     @SWG\SecurityScheme(
 *          securityDefinition="apiKey",
 *          type="apiKey",
 *          in="header",
 *          name="apiKey"
 *     )
 * )
 */
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
        $errorMessage = "You are not authorized to access the api without an apiKey or a wrong apiKey, please provide one in the request header";
        $response = $response->withStatus(401, $errorMessage);
        $response->getBody()->write($errorMessage);
        return $response;
    } else {
        connection::success('api');
        return $next($request, $response);
    }
});

/*******************************
 *      global methods         *
 *******************************/

/**
 * @SWG\Get(
 *     path="/ping",
 *     summary="Ping the system to check if it is alive",
 *     tags={"Global operations"},
 *     produces={"text/plain"},
 *     @SWG\Response(
 *         response=200,
 *         description="Will answer 'pong' if system is alive"
 *     )
 * )
 */
$app->get('/ping', function (Request $request, Response $response) {
        $response->getBody()->write("pong");
        return $response;
});

/**
 * @SWG\Get(
 *     path="/version",
 *     summary="Get the version of the system",
 *     tags={"Global operations"},
 *     produces={"text/plain"},
 *     @SWG\Response(
 *         response=200,
 *         description="Will answer the current version of the system (ex: 2.2.6)"
 *     ),
 *     security={
 *       {"apiKey": {}}
 *     }
 * )
 */
$app->get('/version', function (Request $request, Response $response) {
    $response->getBody()->write(jeedom::version());
    return $response;
});

/**
 * @SWG\Get(
 *     path="/datetime",
 *     summary="Get the datetime of the system",
 *     tags={"Global operations"},
 *     produces={"text/plain"},
 *     @SWG\Response(
 *         response=200,
 *         description="Will answer the current datetime on the system"
 *     ),
 *     security={
 *       {"apiKey": {}}
 *     }
 * )
 */
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

    /**
     * @SWG\Get(
     *     path="/objects",
     *     summary="Get all the objects",
     *     tags={"Objects operations"},
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *         name="full",
     *         in="query",
     *         description="Specify this parameter to retrieve the full details of the objects",
     *         required=false,
     *         type="boolean"
     *     ),
     *     @SWG\Parameter(
     *         name="tree",
     *         in="query",
     *         description="Specify this parameter to retrieve the objects as a tree (parent-children)",
     *         required=false,
     *         type="boolean"
     *     ),
     *     @SWG\Parameter(
     *         name="onlyVisible",
     *         in="query",
     *         description="Specify this parameter to retrieve only visible objects",
     *         required=false,
     *         type="boolean"
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Will return the complete list of object in the system"
     *     ),
     *     security={
     *       {"apiKey": {}}
     *     }
     * )
     */
    $app->get('', function ($request, $response) {
        parse_str($request->getUri()->getQuery(), $params);

        $onlyVisible = ($params['onlyVisible'] === 'true' || $params['onlyVisible'] === '');

        if (isset($params['full']) && ($params['full'] === '' || $params['full'] === 'true')) {
            $result = array();
            foreach (object::all($onlyVisible) as $object) {
                $object_return = getFullObject($object);
                $result[] = $object_return;
            }
        } else if (isset($params['tree']) && ($params['tree'] === '' || $params['tree'] === 'true')) {
            $result = object::tree($onlyVisible);
        } else {
            $result = utils::o2a(object::all($onlyVisible));
        }
        $response->getBody()->write(json_encode($result));
        return $response;
    });

    /**
     * @SWG\Get(
     *     path="/objects/{id}",
     *     summary="Get the description of one object",
     *     tags={"Objects operations"},
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of object to return",
     *         required=true,
     *         type="integer",
     *         format="int64"
     *     ),
     *     @SWG\Parameter(
     *         name="full",
     *         in="query",
     *         description="Specify this parameter to retrieve the full details of the object",
     *         required=false,
     *         type="boolean"
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Will return the description of one object identified by id"
     *     ),
     *     security={
     *       {"apiKey": {}}
     *     }
     * )
     */
    $app->get('/{id:\d+}', function ($request, $response, $args) {
        $object = object::byId($args['id']);
        if (!is_object($object)) {
            $response = $response->withStatus(404, "Object '" . $args['id'] . "' does not exist");
        } else {
            parse_str($request->getUri()->getQuery(), $params);
            $return = utils::o2a($object);
            if (isset($params['full']) && ($params['full'] === '' || $params['full'] === 'true')) {
                $return = getFullObject($object);
            }
            $response->getBody()->write(json_encode($return));
        }
        return $response;
    });
});

$app->run();
