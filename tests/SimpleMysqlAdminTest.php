<?php
namespace Vanderbilt\SimpleMysqlAdmin;

require_once __DIR__ . '/../../../redcap_connect.php';

class SimpleMysqlAdminTest extends \ExternalModules\ModuleBaseTest
{
	function testIsQueryType(){
		$assert = function($query, $types, $expected){
			$actual = $this->module->isQueryType($query, $types);
			$this->assertSame($expected, $actual);
		};

		$assert('select', 'select', false);
		$assert('select ', 'select', true);
		$assert('delete ', 'select', false);
		$assert("select\t", 'select', true);
		$assert("select\n", 'select', true);
		$assert('SeLeCt ', 'sElEcT', true);
		$assert('show ', ['select', 'show'], true);
		$assert('show ', [], false);

		$this->expectExceptionMessage('Empty types are not allowed');
		$assert('whatever ', [''], false);
	}
}
