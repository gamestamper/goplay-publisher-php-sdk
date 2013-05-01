<?
function flash($msg) {
	setcookie('__flash_messages',json_encode(array_merge(getFlash(),array($msg))),time()+2);
}

function getFlash() {
	return isset($_COOKIE['__flash_messages']) ? json_decode($_COOKIE['__flash_messages']) : array();
}

function readFlash() {
	$result = getFlash();
	setcookie('__flash_messages', '', time()-3600);
	unset($_COOKIE['__flash_messages']);
	return $result;
}

include_once dirname(__FILE__).'/PublisherSDK.php';
$sdk = new PublisherSDK('pbgspub', 'a8sdfjweuy3456', true);

if (count($_POST)>0) {
	switch (strtolower($_POST['action'])) {
		case 'save':
			$resp = $sdk->pub->playersClub->post(array('players'=>array(array('email'=>$_POST['email'],'birthday'=>$_POST['birthday'],'accountId'=>$_POST['accountId']))));
			flash('Response from post was: '.json_encode($resp->response));
			break;
		case 'delete':
			$resp = $sdk->pub->playersClub->delete($_POST['accountId']);
			flash('Response from delete was: '.json_encode($resp->response));
			break;
	}
	header('Location: '.$_SERVER['SCRIPT_URI']);
}
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Sample Publisher SDK App</title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
		<style>
			body{font-family:Arial;font-size:12px}
		</style>
	</head>
	<body>
		<header>Sample Publisher SDK App</header>
		<pre><?=json_encode($sdk->pub->playersClub->get()->data)?></pre>
		<pre>Token: <?=$sdk->getToken()?></pre>
		<ul>
			<?foreach (readFlash() as $msg) {?>
			<li><?=$msg?></li>
			<?}?>
		</ul>
		<form method="POST">
			<ul>
				<li><label>Email <input type="text" name="email"/></label></li>
				<li><label>Birthday <input type="text" name="birthday"/></label></li>
				<li><label>Account Id <input type="text" name="accountId"/></label></li>
				<li><input type="submit" name="action" value="Save"/> <input type="submit" name="action" value="Delete"/></li>
			</ul>
		</form>
	</body>
</html>