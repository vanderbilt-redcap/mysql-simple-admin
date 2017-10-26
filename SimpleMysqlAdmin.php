<?php
namespace Vanderbilt\SimpleMysqlAdmin;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class SimpleMysqlAdmin extends AbstractExternalModule {
	function redcap_control_center() {
		?>
		<script type="text/javascript">
		$(function(){
			$('#control_center_menu div.cc_menu_item a[href*="mysql_dashboard.php"]').parent().after('<div class="cc_menu_item"><img src="'+app_path_images+'database_table.png">&nbsp; <a href="<?php echo $this->getUrl("index.php") ?>">MySQL Simple Admin</a></div>');
		});
		</script>		
		<?php
	}
	function getPageUrl($page) {
		return $this->getUrl($page);
	}
}
