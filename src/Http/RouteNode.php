<?php

namespace Sapling\Http;

/**
 * A level of Router's tree, with a branch for each fixed segment and one for a :param.
 *
 * @internal
 */
final class RouteNode
{
	/** @var array<string, self> */
	public array $fixed = [];

	public ?self $param = null;

	/**
	 * @var array<string, array{
	 *     action: class-string|object,
	 *     middleware: list<class-string|object>,
	 *     params: list<string>,
	 *     path: string,
	 * }>
	 */
	public array $routes = [];
}
