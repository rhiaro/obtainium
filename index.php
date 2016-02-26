<?
session_start();
date_default_timezone_set(file_get_contents("http://rhiaro.co.uk/tz"));
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header("Location: /obtainium"); }
if(isset($_GET['reset']) && $_GET['reset'] == "images") { $_SESSION['images'] = set_default_images(); header("Location: /obtainium"); }
if(isset($_GET['reset']) && $_GET['reset'] == "feed") { unset($_SESSION['feed']); unset($_SESSION['feed_source']); header("Location: /obtainium"); }

include "link-rel-parser.php";

$base = "https://apps.rhiaro.co.uk/obtainium";
if(isset($_GET['code'])){
  $auth = auth($_GET['code'], $_GET['state']);
  if($auth !== true){ $errors = $auth; }
  else{
    $response = get_access_token($_GET['code'], $_GET['state']);
    if($response !== true){ $errors = $auth; }
    else {
      header("Location: ".$_GET['state']);
    }
  }
}

// VIP cache
$vips = array("http://rhiaro.co.uk", "http://rhiaro.co.uk/", "http://tigo.rhiaro.co.uk/");
$images = set_default_images();

if(isset($_SESSION['images'])){
  $images = $_SESSION['images'];
}

if(isset($_POST['images_source'])){
  $fetch = get_images($_POST['images_source']);
  if(!$fetch){
    $errors["Problem fetching images"] = "The images url needs to return a single page AS2 Collection as JSON[-LD]. {$_POST['images_source']} did not return this.";
  }else{
    $images = $fetch;
  }
}

if(isset($_POST['feed_source'])){
  $feed = get_feed($_POST['feed_source']);
  if(!$feed){
    $errors["Problem fetching feed"] = "Something went wrong";
  }
}

function dump_headers($curl, $header_line ) {
  echo "<br>YEAH: ".$header_line; // or do whatever
  return strlen($header_line);
}

function auth($code, $state, $client_id="https://apps.rhiaro.co.uk/obtainium"){
  
  $params = "code=".$code."&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=".$client_id;
  $ch = curl_init("https://indieauth.com/auth");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded", "Accept: application/json"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  //curl_setopt($ch, CURLOPT_HEADERFUNCTION, "dump_headers");
  $response = curl_exec($ch);
  $response = json_decode($response, true);
  $_SESSION['me'] = $response['me'];
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    return true;
  }
}

function get_access_token($code, $state, $client_id="https://apps.rhiaro.co.uk/obtainium"){
  
  $params = "me={$_SESSION['me']}&code=$code&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=$client_id";
  $token_ep = discover_endpoint($_SESSION['me'], "token_endpoint");
  $ch = curl_init($token_ep);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    $_SESSION['access_token'] = $response['access_token'];
    return true;
  }
  
}

function discover_endpoint($url, $rel="micropub"){
  if(isset($_SESSION[$rel])){
    return $_SESSION[$rel];
  }else{
    $res = head_http_rels($url);
    $rels = $res['rels'];
    if(!isset($rels[$rel][0])){
      $parsed = json_decode(file_get_contents("https://pin13.net/mf2/?url=".$url), true);
      if(isset($parsed['rels'])){ $rels = $parsed['rels']; }
    }
    if(!isset($rels[$rel][0])){
      // TODO: Try in body
      return "Not found";
    }
    $_SESSION[$rel] = $rels[$rel][0];
    return $rels[$rel][0];
  }
}

function context(){
  return array(
      "@context" => array("as" => "http://www.w3.org/ns/activitystreams#", "blog" => "http://vocab.amy.so/blog#")
    );
}

function get_images($source=null){
  if($source){
    // TODO: get images from source
    if(is_array($_SESSION['images']) && !empty($_SESSION['images']) && $_SESSION['images_source'] == $source){
      return $_SESSION['images'];
    }else{
      $ch = curl_init($source);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json"));
      $response = curl_exec($ch);
      curl_close($ch);
      $collection = json_decode($response, true);
      if($collection){
        if(is_array($collection["@context"])) $aspref = array_search("http://www.w3.org/ns/activitystreams#", $collection["@context"]);
        if(isset($aspref)){
          $allimages = $collection[$aspref.":items"];
        }else{
          $allimages = $collection["items"];
        }
        $justurls = array();
        foreach($allimages as $imgdata){
          $justurls[] = $imgdata["@id"];
        }
        rsort($justurls);
        //$_SESSION['images'] = array_slice($justurls, 0, 100);
        $_SESSION['images'] = $justurls;
        $_SESSION['images_source'] = $collection["@id"];
        return $_SESSION['images'];
      }
    }
  }
  return false;
}

function get_feed($source=null){
  if($source){
    if(is_array($_SESSION['feed']) && !empty($_SESSION['feed']) && $_SESSION['feed_source'] == $source){
      return $_SESSION['feed'];
    }else{
      $ch = curl_init($source);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json"));
      $response = curl_exec($ch);
      curl_close($ch);
      $collection = json_decode($response, true);
      $_SESSION['feed'] = array();
      foreach($collection['items'] as $data){
        $_SESSION['feed'][$data["@id"]] = $data;
      }
      $_SESSION['feed_source'] = $source;
      return $_SESSION['feed'];
    }
  }
}

function set_default_images(){
  return get_images("http://img.amy.gy/obtainium");
}

function form_to_json($post){
  $context = context();
  $data = array_merge($context, $post);
  unset($data['obtain']);
  $data["@type"] = array("blog:Acquisition");
  $data['as:published'] = $post['year']."-".$post['month']."-".$post['day']."T".$post['time'].$post['zone'];
  unset($data['year']); unset($data['month']); unset($data['day']); unset($data['time']); unset($data['zone']);
  if(isset($post['image'])) $data['as:image'] = array("@id" => $post['image'][0]);
  $json = stripslashes(json_encode($data, JSON_PRETTY_PRINT));
  return $json;
}

function post_to_endpoint($json, $endpoint){
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/activity+json"));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer ".$_SESSION['access_token']));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  return $response;
}

if(isset($_GET['post'])){
  if(isset($_SESSION['feed'][$_GET['post']]['summary'])){ $upd_descr = $_SESSION['feed'][$_GET['post']]['summary']; }
  if(isset($_SESSION['feed'][$_GET['post']]['http://vocab.amy.so/blog#cost'])){ $upd_cost = $_SESSION['feed'][$_GET['post']]['http://vocab.amy.so/blog#cost']; }
  if(isset($_SESSION['feed'][$_GET['post']]['tag'])){
    if(is_array($_SESSION['feed'][$_GET['post']]['tag'])) { $upd_tag = implode(', ', $_SESSION['feed'][$_GET['post']]['tag']); }
    else{ $upd_tag = $_SESSION['feed'][$_GET['post']]['tag']; }
  }
  if(isset($_SESSION['feed'][$_GET['post']]['published'])){
    $upd_date = $_SESSION['feed'][$_GET['post']]['published'];
    $d = new DateTime($upd_date);
    $upd_day = $d->format('j');
    $upd_month = $d->format('n');
    $upd_year = $d->format('Y');
    $upd_time = $d->format('H:i:s');
    $upd_tz = $d->format('P');
  }
  if(isset($_SESSION['feed'][$_GET['post']]['image'])){ $upd_image = $_SESSION['feed'][$_GET['post']]['image']; }
}

if(isset($_POST['obtain'])){
  if(isset($_SESSION['me'])){
    $endpoint = discover_endpoint($_SESSION['me']);
    $result = post_to_endpoint(form_to_json($_POST), $endpoint);
  }else{
    $errors["Not signed in"] = "You need to sign in to post.";
  }
}

?>
<!doctype html>
<html>
  <head>
    <title>Obtainium</title>
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>
  <body>
    <main class="w1of2 center">
      <h1>Obtainium</h1>
      
      <?if(isset($errors)):?>
        <div class="fail">
          <?foreach($errors as $key=>$error):?>
            <p><strong><?=$key?>: </strong><?=$error?></p>
          <?endforeach?>
        </div>
      <?endif?>
      
      <?if(isset($result)):?>
        <div>
          <p>The response from you your micropub endpoint:</p>
          <code><?=$endpoint?></code>
          <pre>
            <? var_dump($result); ?>
          </pre>
        </div>
      <?endif?>
      
      <form method="post" role="form" id="obtain">
        <p><input type="submit" value="<?=isset($_GET['post']) ? "Update" : "Post"?>" class="neat" name="obtain" /></p>
        <p><label for="summary" class="neat">Description</label> <input type="text" name="as:summary" id="summary" class="neat"<?=isset($upd_descr) ? 'value="'.$upd_descr.'"' : ""?> /></p>
        <p><label for="cost" class="neat">Cost</label> <input type="text" name="blog:cost" id="cost"class="neat"<?=isset($upd_cost) ? 'value="'.$upd_cost.'"' : ""?> /></p>
        <p><label for="tags" class="neat">Tags</label> <input type="text" name="as:tag" id="tags"class="neat"<?=isset($upd_tag) ? 'value="'.$upd_tag.'"' : ""?> /></p>
        <p>
          <select name="year" id="year">
            <option value="2016"<?isset($upd_year) && ($upd_year == "2016") ? " selected" : ""?>>2016</option>
            <option value="2015"<?isset($upd_year) && ($upd_year == "2015") ? " selected" : ""?>>2015</option>
          </select>
          <select name="month" id="month">
            <?for($i=1;$i<=12;$i++):?>
              <option value="<?=date("m", strtotime("2016-$i-01"))?>"
              <?if(!isset($upd_month)):?>
                <?=(date("n") == $i) ? " selected" : ""?>
              <?else:?>
                <?=($upd_month == $i) ? " selected" : ""?>
              <?endif?>><?=date("M", strtotime("2016-$i-01"))?></option>
            <?endfor?>
          </select>
          <select name="day" id="day">
            <?for($i=1;$i<=31;$i++):?>
              <option value="<?=date("d", strtotime("2016-01-$i"))?>"
              <?if(!isset($upd_day)):?>
                <?=(date("j") == $i) ? " selected" : ""?>
              <?else:?>
                <?=($upd_day == $i) ? " selected" : ""?>
              <?endif?>><?=date("d", strtotime("2016-01-$i"))?></option>
            <?endfor?>
          </select>
          <input type="text" name="time" id="time" value="<?=isset($upd_time) ? $upd_time : date("H:i:s")?>" />
          <input type="text" name="zone" id="zone" value="<?=isset($upd_tz) ? $upd_tz : date("P")?>" />
        </p>
        <ul class="clearfix">
          <?foreach($images as $image):?>
            <li class="w1of5"><p><input type="radio" name="image[]" id="image" value="<?=$image?>" <?=isset($upd_image) && $upd_image == $image ? " checked" : ""?> /> <label for="image"><img title="<?=$image?>" src="https://images1-focus-opensocial.googleusercontent.com/gadgets/proxy?url=<?=$image?>&container=focus&resize_w=200&refresh=2592000" width="100px" /></label></p></li>
          <?endforeach?>
        </ul>
      </form>
      
      <div class="color3-bg inner">
        <?if(isset($_SESSION['me'])):?>
          <p class="wee">You are logged in as <strong><?=$_SESSION['me']?></strong> <a href="?logout=1">Logout</a></p>
        <?else:?>
          <form action="https://indieauth.com/auth" method="get" class="inner clearfix">
            <label for="indie_auth_url">Domain:</label>
            <input id="indie_auth_url" type="text" name="me" placeholder="yourdomain.com" />
            <input type="submit" value="signin" />
            <input type="hidden" name="client_id" value="http://rhiaro.co.uk" />
            <input type="hidden" name="redirect_uri" value="<?=$base?>" />
            <input type="hidden" name="state" value="<?=$base?>" />
            <input type="hidden" name="scope" value="post" />
          </form>
        <?endif?>
        
        <h2>Customise</h2>
        <h3>Images</h3>
        <form method="post" class="inner wee clearfix">
          <p>If you have a directory with images you'd like to choose from, enter the URL here.</p>
          <label for="images_source">URL of a list of images:</label>
          <input id="images_source" name="images_source" value="<?=isset($_SESSION['images_source']) ? $_SESSION['images_source'] : ""?>" />
          <input type="submit" value="Fetch" /> <a href="?reset=images">Reset</a>
        </form>
        <h3>Feed</h3>
        <form method="post" class="inner wee clearfix">
          <p>If you have a public feed of obtainium posts you'd like to edit/delete, enter the URL here.</p>
          <label for="feed_source">URL of a list of feed:</label>
          <input id="feed_source" name="feed_source" value="<?=isset($_SESSION['feed_source']) ? $_SESSION['feed_source'] : ""?>" />
          <input type="submit" value="Fetch" /> <a href="?reset=feed">Reset</a>
        </form>
        <ul>
          <?if(isset($_SESSION['feed'])):?>
            <?foreach($_SESSION['feed'] as $k => $data):?>
              <li><a href="?post=<?=$data["@id"]?>"><?=$data["summary"]?></a></li>
            <?endforeach?>
          <?endif?>
        </ul>
        <h3>Post...</h3>
        <form method="post" class="inner wee clearfix">
          <select name="posttype">
            <option value="as2" selected>AS2 JSON</option>
            <option value="mp" disabled>Micropub (form-encoded)</option>
            <option value="mp" disabled>Micropub (JSON)</option>
            <option value="ttl" disabled>Turtle</option>
          </select>
          <input type="submit" value="Save" />
        </form>
      </div>
    </main>
  </body>
</html>