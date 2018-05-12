<script type='text/javascript' src="bower_components/dist/print.min.js"></script>
<script type="text/javascript">	
<?php 
$file_name=$sale_id.''.'.pdf';
$url=base_url().''.$file_name;
if($print_after_sale)
{
?>
	$(window).load(function() 
	{
	   printJS("<?php echo $url; ?>");
	}); 
<?php
}
?>
</script>