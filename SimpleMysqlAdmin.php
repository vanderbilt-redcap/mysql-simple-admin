<?php
namespace Vanderbilt\SimpleMysqlAdmin;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class SimpleMysqlAdmin extends AbstractExternalModule {
	function redcap_control_center() {
		?>
		<script type="text/javascript">
		$(function(){
			$('#control_center_menu div.cc_menu_item a[href*="mysql_dashboard.php"]').parent().after('<div class="cc_menu_item"><i class="fas fa-th" style="color:#A00000;text-indent:0;margin-left:2px;margin-right:3px;"></i> <a style="color:#800000;" href="<?php echo $this->getUrl("index.php") ?>">MySQL Simple Admin</a></div>');
		});
		</script>		
		<?php
	}
	function getPageUrl($page) {
		return $this->getUrl($page);
	}
}
