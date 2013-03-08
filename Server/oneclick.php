<?php

// oneclick.php
////////////////////////////////////////////////////////////////////////////////
///
/// \fn newOneClick()()
///
/// \brief prints form for submitting a new OneClick
///
////////////////////////////////////////////////////////////////////////////////
function newOneClick() {
	global $submitErr, $user, $mode;
	print "<H2>OneClick Configurator</H2>\n";

	print "<div id=\"mainTabContainer\" dojoType=\"dijit.layout.TabContainer\"\n";
	print "     style=\"width:800px;height:600px\">\n";


	print "<div id=\"newOneClick\" dojoType=\"dijit.layout.ContentPane\" title=\"New OneClick Configuration\">\n";

	print '<script>';
	print '	function validateForm(form) {';
	print '		var errors = "";';
	print '		if(document.getElementById("newOneClickName").value == "") {';
	print '			errors += "\t - Insert a name for the OneClick";';
	print '		}';
	print '		if(errors != "") {';
	print '			alert("Please fix the following errors before submitting the form:\n" + errors);';
	print '			return false;';
	print '		}';
	print '		return true;';
	print '	}';
	print '</script>';

	print "<FORM action=\"" . BASEURL . SCRIPT . "\" method=post  onsubmit=\"return validateForm(this);\" >\n";
	// name of OneClick
	//********************************************************************************
	print "<br><br>\n";
	print "Choose a name for your new OneClick configuration<br>\n";
	print " \n";
	print "<strong>Name:</strong>\n";
	//print '<input type="text" id="newOneClickName" name="newOneClickName" dojoType=dijit.form.TextBox>\n';
	print '<input type="text" id="newOneClickName" name="newOneClickName">';
	print "</input>\n";
	print "<br><br>\n";


	//print "<FORM action=\"" . BASEURL . SCRIPT . "\" method=post>\n";
	// resureces; image types
	//********************************************************************************
	$imageid = processInputVar("imageid", ARG_STRING, getUsersLastImage($user['id']));
	$imaging = getContinuationVar('imaging', processInputVar('imaging', ARG_NUMERIC, 0));
	$length = processInputVar("length", ARG_NUMERIC);
	if($imaging) {
		$resources = getUserResources(array("imageAdmin"));
		if(empty($resources['image'])) {
			print "You don't have access to any resources from which to create a new OneClick.<br>\n";
			return;
		}
		if($length == '')
			$length = 480;
	}
	else {
		$resources = getUserResources(array("imageAdmin", "imageCheckOut"));
		$resources["image"] = removeNoCheckout($resources["image"]);
	}

	print "Please select the resource you want to use from the list:\n";
	print "<br><br>\n";
	$images = getImages();
	// list of images
//        print "      <select dojoType=\"dijit.form.FilteringSelect\" id=imagesel ";
//        print "onChange=\"selectEnvironment();\" tabIndex=1 style=\"width: 400px\" ";
//        print "queryExpr=\"*\${0}*\" highlightMatch=\"all\" autoComplete=\"false\" ";
//        print "name=imageid>\n";
	print '      <select name=imageid>';
	foreach($resources['image'] as $id => $image)
		if($id == $imageid)
			print "        <option value=\"$id\" selected>$image</option>\n";
		else
			print "        <option value=\"$id\">$image</option>\n";
	print "      </select>\n";
	print "<br><br>\n";

	//list of duration of the severvation from this OneClick
	//********************************************************************************

	if(array_key_exists($imageid, $images))
		$maxlength = $images[$imageid]['maxinitialtime'];
	else
		$maxlength = 0;
	# create an array of usage times based on the user's max times
	$maxtimes = getUserMaxTimes();
	if($maxlength > 0 && $maxlength < $maxtimes['initial'])
		$maxtimes['initial'] = $maxlength;
	if($imaging && $maxtimes['initial'] < 720) # make sure at least 12 hours available for imaging reservations
		$maxtimes['initial'] = 720;
	$lengths = array();
	if($maxtimes["initial"] >= 30)
		$lengths["30"] = "30 minutes";
	if($maxtimes["initial"] >= 60)
		$lengths["60"] = "1 hour";
	for($i = 120; $i <= $maxtimes["initial"] && $i < 2880; $i += 60)
		$lengths[$i] = $i / 60 . " hours";
	for($i = 2880; $i <= $maxtimes["initial"]; $i += 1440)
		$lengths[$i] = $i / 1440 . " days";
	print "<Strong>Duration:</Strong>&nbsp;\n";
	printSelectInput("length", $lengths, $length, 0, 0, 'reqlength', "onChange='updateWaitTime(0);'");
	print "<br>\n";
	print "<br><br>\n";


// other choice
	print "<INPUT type=\"checkbox\" name=\"autologin\" value = \"1\">";
	print "Auto Login";
	print "<br><br>\n";
	/* print "<INPUT type=\"checkbox\" name=\"notimeout\" value = \"1\">";
	  print "No TimeOut";
	  print "<br><br>\n"; */


// submit button
	//********************************************************************************
	$cont = addContinuationsEntry('submitOneClick', array(), SECINDAY, 1, 0);
	print "<INPUT type=hidden name=continuation value=\"$cont\">\n";
	print "<INPUT type=submit value=\"Create OneClick Configuration\">\n";

	print "</FORM>\n";
	// end of first tag
	//********************************************************************************
	print "</div>\n";
	print "<br><br>\n";


	// the tab that list all the OneClicks the user have
	print "<div id=\"listOneClick\" dojoType=\"dijit.layout.ContentPane\" title=\"List of OneClick Configurations\">\n";
	
	
	// query db, get the list of OneClicks
	global $user;
	$uid = $user['id'];
	//dbConnect();
	$query = "SELECT oneclick.*, image.prettyname imagename "
			. "FROM oneclick JOIN image ON oneclick.imageid = image.id "
			. "WHERE oneclick.status = 1 AND oneclick.userid = $uid";

	$qh = doQuery($query, 101);
	if(!mysql_num_rows($qh)) {
		return NULL;
	}
	$no_of_rows = mysql_num_rows($qh);

	while($no_of_rows >= 1) {
		$no_of_rows = $no_of_rows - 1;
		$row = mysql_fetch_row($qh);
		print '<fieldset id="list" class="oneclicklist">';

		print 'OneClick Name: ';
		$oneclickname = $row[3];
		print '<strong>' . htmlentities($oneclickname) . '</strong>';
		print '<br><br>';

		//print 'ID:';
		$oneclickid = $row[0];
		//print '<strong>' . htmlentities($oneclickid) . '</strong>';

		print 'Resource: <strong>' . htmlentities($row[7]) . '</strong><br>';
		//Duration
		$duration = $row[4];
		if($duration < 60) {
			print 'Duration:    <strong>' . $duration . ' minutes</strong><br>';
		} else {
			if($duration < (60 * 24)) {
				$hourduration = (int) $duration / 60;
				print "Duration:    <strong>" . $hourduration . " hour" . ($hourduration == 1 ? "" : "s") . "</strong><br>";
			} else {
				$dayduration = (int) $duration / (60 * 24);
				print "Duration:    <strong>" . $dayduration . " day" . ($dayduration == 1 ? "" : "s") . "</strong><br>";
			}
		}
		print 'AutoLogin:   <strong>' . ($row[5] == 1 ? "Yes" : "No") . '</strong><br>';
		print '<br>';

		$platform = "";

		if(stristr($_SERVER['HTTP_USER_AGENT'], "like Mac OS X")) {
			$platform = 'ios';
		} elseif(stristr($_SERVER['HTTP_USER_AGENT'], "Android")) {
			$platform = 'android';
		} elseif(stristr($_SERVER['HTTP_USER_AGENT'], "Windows")) {
			$platform = 'win';
		} elseif(stristr($_SERVER['HTTP_USER_AGENT'], "Linux")) {
			$platform = 'linux';
		} elseif(stristr($_SERVER['HTTP_USER_AGENT'], "Macintosh")) {
			$platform = 'mac';
		}

		//download button
		//********************************************************************************

		print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;">';
		//print '<form action="index.php" method="post" style="display:inline">';
		print '      <select name="platform">';
		print '        <option value="win" ' . ($platform == "win" ? 'selected="selected"' : "") . '>Microsoft Windows</option>';
		print '        <option value="mac" ' . ($platform == "mac" ? 'selected="selected"' : "") . '>Mac OS X</option>';
		print '        <option value="linux" ' . ($platform == "linux" ? 'selected="selected"' : "") . '>Linux</option>';
		print '        <option value="android" ' . ($platform == "android" ? 'selected="selected"' : "") . '>Android</option>';
		print '        <option value="ios" ' . ($platform == "ios" ? 'selected="selected"' : "") . '>iOS</option>';
		print '      </select>';
		$oneclickidval = $row[0];
		//print '<input type=hidden name=oneclickid value=\"$row[0]\">';
		print '<input type="hidden" name="oneclickid" value="' . $oneclickidval . '">';
		$cont = addContinuationsEntry('downloadOneClick', array(), SECINDAY, 1, 1);
		print '<input type="hidden" name="continuation" value="' . $cont . '">';
		print '<input type="submit" value="Download">';
		//print '<input id=downloadOneClick type=submit value=\"Download OneClick\" ';
		//print 'onClick=\"return checkValidImage();\">';
		// downloxad function
		print '</form>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';

		//edit button
		//********************************************************************************
		//print '<form action="index.php" method="post" style="display:inline">';
		print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;">';
		//edit button
		//print '<input id=editOnebutton type=submit value=\"Edit OneClick\" ';
		print '<input type="hidden" name="oneclickid" value="' . $oneclickidval . '">';
		print '<input type="hidden" name="oneclickname" value="' . $oneclickname . '">';
		$cont = addContinuationsEntry('editOneClick', array(), SECINDAY, 1, 0);
		print '<input type="hidden" name="continuation" value="' . $cont . '">';
		print '<input type="submit" value="Edit">';
		print '</form>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';


		//Delete button
		//********************************************************************************
		//print '<form action="index.php" method="post" style="display:inline">';
		print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;">';
		$oneclickidval = $row[0];
		//print '$oneclickidval';
		print '<input type="hidden" name="oneclickid" value="' . $oneclickidval . '">';
		$cont = addContinuationsEntry('deleteOneClick', array(), SECINDAY, 1, 0);
		print '<input type="hidden" name="continuation" value="' . $cont . '">';
		print '<input type="submit" value="Delete">';
		print '</form>';
		//print '<input id=deleteOneClick type=submit value=\"Delete OneClick\" ';
		//print 'onClick=\"return checkValidImage();\">';
		// Delete function

		print '</fieldset>';
		print '<br><br>';
	}
	
	
	print "</div>\n";
	print "</div>\n";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn submitOneClick()
///
/// \to create one Button from Web Configurator
///
////////////////////////////////////////////////////////////////////////////////
function submitOneClick() {
	//process the input variables in the GET/POST request from submit button
	global $user;
	$userid = $user['id'];
	$imageid = processInputVar("imageid", ARG_NUMERIC);
	$name = processInputVar("newOneClickName", ARG_STRING);
	$duration = processInputVar("length", ARG_NUMERIC);
	$autologin = processInputVar("autologin", ARG_STRING) == 1 ? 1 : 0;
	//connect to the database to insert one button entry
	//dbConnect();
	$query = "INSERT INTO oneclick"
			. "(userid, "
			. "imageid, "
			. "name, "
			. "duration, "
			. "autologin, "
			. "status) "
			. "VALUES "
			. "($userid, "
			. "$imageid, "
			. "'$name', "
			. "$duration, "
			. "$autologin, "
			. "1) ";
	$qh = doQuery($query, 101);

	//dbDisconnect(); //disconnect from database
	//
    //display message to user that oneclick with name has been succesfully created in the db.
	print '<p>&nbsp;</p>';
	print '<h2>OneClick Configurator Result</h2>';
	print '<p>&nbsp;</p>';
	print '<p>The OneClick configuration <strong>' . htmlentities($name) . '</strong> has been sucessfully created.</p>';
	print '<p>You can go to the <a href="index.php?mode=newOneClick">OneClick List</a> to download an instance for your platform.</p>';
}

/// \fn deleteOneClick()
///
/// \to delete one Button from Web Configurator
///
////////////////////////////////////////////////////////////////////////////////
function deleteOneClick() {
	//process the input variables in the GET/POST request from submit button
	$oneclickid = processInputVar("oneclickid", ARG_NUMERIC);
	$oneclickname = processInputVar("oneclickname", ARG_STRING);
	//print "$oneclickid"; //: $oneclickid";
	//dbConnect();
	$query = "UPDATE oneclick SET status = 0 WHERE id = {$oneclickid}";
	$qh = doQuery($query, 150);

	if($qh) {
		//$row = mysql_fetch_row($qh);
		//$oneclickname = $row[3];
		//display message to user that oneclick with name has been succesfully deleted in the db.
		print '<p>&nbsp;</p>';
		print '<h2>OneClick Configurator Result</h2>';
		print '<p>&nbsp;</p>';
		print '<p>The OneClick configuration <strong>' . $oneclickname . '</strong> has been sucessfully Deleted.</p>';
		print '<p>You can go to the <a href="index.php?mode=newOneClick">OneClick List</a> to download an instance for your platform.</p>';
	} else {
		print '<p>&nbsp;</p>';
		print '<h2>OneClick Configurator Delete FAILED!!!!</h2>';
		print '<p>&nbsp;</p>';
		print '<p>The OneClick configuration <strong>' . $oneclickname . '</strong> has not been Deleted.</p>';
		print '<p>You can go to the <a href="index.php?mode=newOneClick">OneClick List</a> to view all your instances.</p>';
	}
	//dbDisconnect();  
}

/// \fn editOneClick()
///
/// \to edit one Button from Web Configurator
///
////////////////////////////////////////////////////////////////////////////////
function editOneClick() {
	//process the input variables in the GET/POST request from submit button
	$oneclickid = processInputVar("oneclickid", ARG_NUMERIC);
	$oneclickname = processInputVar("oneclickname", ARG_STRING);
//    print "$oneclickname";
//    print '<br>';
//    print "$oneclickid";
//    print '<br>';
	// connect to data base
	global $user;
	$uid = $user['id'];
//    dbConnect();

	$query = "SELECT oneclick.*, image.prettyname imagename, image.id imageid "
			. "FROM oneclick JOIN image ON oneclick.imageid = image.id "
			. "WHERE oneclick.status = 1 AND oneclick.id = $oneclickid AND oneclick.userid = $uid";

	print '<script>';
	print '	function validateForm(form) {';
	print '		var errors = "";';
	print '		if(document.getElementById("oneClickName").value == "") {';
	print '			errors += "\t - Insert a name for the OneClick";';
	print '		}';
	print '		if(errors != "") {';
	print '			alert("Please fix the following errors before submitting the form:\n" + errors);';
	print '			return false;';
	print '		}';
	print '		return true;';
	print '	}';
	print '</script>';

	$qh = doQuery($query, 101);
	print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;" onsubmit="return validateForm(this);">';
	// check if get the record correctly
	if(!mysql_num_rows($qh)) {
		print "different name of oneclick";
		return NULL;
	}
	$row = mysql_fetch_row($qh);
	$oneclickname_alias = $row[3];
	//print "$row[3]";
	if($oneclickname != $oneclickname) {
		return NULL;
	}
	print '<p>&nbsp;</p>';
	print '<h2>OneClick Edittor</h2>';



	print '<br>';
	// infomations
	// Name **********************************************************************************************
	print 'OneClick Name: ';
	print '<input type="text" id="oneClickName" name="oneClickName" value="' . htmlentities($row[3]) . '">';
	//print '<strong>'.htmlentities($oneclickname).'</strong>';    
	print '<br><br>';
	// Image Type **********************************************************************************************
	print 'Resource: <strong>' . htmlentities($row[8]) . '</strong><br>';
	print '<br><br>';

	//Duration **********************************************************************************************
	$preduration = $row[4];
//    print "$preduration\n";
	$images = getImages();
	$maxlength = $images[$row[9]]['maxinitialtime'];
//    print"$maxlength\n";
	$maxtimes = getUserMaxTimes();
//    $abc = $maxtimes['initial'];
//    print "$abc\n";
	$imaging = getContinuationVar('imaging', processInputVar('imaging', ARG_NUMERIC, 0));
//print "$imaging";
	if($maxlength == 0 || $maxlength < 0)
		$maxlength = $maxtimes['initial'];
	else
		$maxlength = $maxtimes['initial'] > $maxlength ? $maxlength : $maxtimes['initial'];
	if($imaging && $maxlength < 720) # make sure at least 12 hours available for imaging reservations
		$maxlength = 720;
	$iteri = 30;
	print '<strong>Duration:&nbsp</strong>';
	print '      <select name="duration">';
	for($iteri = 30; $iteri <= $maxlength; $iteri+=60) {
		if($iteri == 30) {
			print "<option value=\"$iteri\" " . ($iteri == $preduration ? "selected" : "") . "> 30 minutes</option>\n";
			$iteri+=30;
		}
		if($iteri >= 60 && $iteri < 1440) {
			$temphour = (int) $iteri / 60;
			print "<option value=\"$iteri\" " . ($iteri == $preduration ? "selected" : "") . "> $temphour hour" . ($temphour == 1 ? "" : "s") . "</option>\n";
			continue;
		}
		if($iteri > 1440) {
			$tempday = (int) $iteri / 1440;
			print "<option value=\"$iteri\" " . ($iteri == $preduration ? "selected" : "") . "> $tempday day" . ($tempday == 1 ? "" : "s") . "</option>\n";
			continue;
		}
	}
	print '      </select>';
	print "<br><br>\n";
	// Auto Login **********************************************************************************************
	print "<INPUT type=\"checkbox\" name=\"autologin\" value = \"1\"" . ($row[5] == 1 ? "checked=\"checked\"" : "") . ">";
	print "Auto Login";
	print "<br><br>\n";
	// NO Time Out **********************************************************************************************
	/* print "<INPUT type=\"checkbox\" name=\"notimeout\" value = \"1\"".($row[6] == 1 ? "checked=\"checked\"" : "").">";
	  print "No TimeOut";
	  print "<br><br>\n"; */
	// submit
	//print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;">';
	print '<input type="hidden" name="oneclickid" value="' . $oneclickid . '">';
	$cont = addContinuationsEntry('submitEdit', array(), SECINDAY, 1, 0);
	print "<INPUT type=hidden name=continuation value=\"$cont\">\n";
	print "<INPUT type=submit value=\"Submit Changes\">\n";
	print '</form>';
	// cancel
	print '<form action="' . BASEURL . SCRIPT . '" method="post" style="display: inline;">';
	$cont = addContinuationsEntry('newOneClick', array(), SECINDAY, 1, 0);
	print "<INPUT type=hidden name=continuation value=\"$cont\">\n";
	print "<INPUT type=submit value=\"Cancel\">\n";
	print '</form>';
	//dbDisconnect(); //disconnect from database
}

/// \fn SubmitEdit()
///
/// \to submit the change to one Button
///
////////////////////////////////////////////////////////////////////////////////
function submitEdit() {
//    print"hello";
	$oneclickid = processInputVar("oneclickid", ARG_NUMERIC);
	//global $user;
	//$userid = $user['id'];
	//$imageid = processInputVar("imageid", ARG_NUMERIC);
	$name = processInputVar("oneClickName", ARG_STRING);
	$duration = processInputVar("duration", ARG_NUMERIC);
	$autologin = processInputVar("autologin", ARG_STRING) == 1 ? 1 : 0;
//    print "$oneclickid<br>";
//    //print "$userid<br>";
//    print "$name<br>";
//    print "$duration<br>";
//    print "$autologin<br>";
//    print "$notimeout<br>"; 
	//dbConnect();
	if($name != "") {
		$query = "UPDATE oneclick "
				. "SET duration = $duration, "
				. "name = '$name', "
				. "autologin = $autologin, "
				. "WHERE id = $oneclickid";
		//	$qh = doQuery($query, 101);
		$qh = doQuery($query, 150);
	} else {
		$qh = 0;
		$error1 = "<br><br>The <strong> name </strong> of your oneClick can not be EMPTY!!";
	}
	if($qh) {
		//$row = mysql_fetch_row($qh);
		//$oneclickname = $row[3];
		//display message to user that oneClick with name has been succesfully deleted in the db.
		print '<p>&nbsp;</p>';
		print '<h2>OneClick Configurator Result</h2>';
		print '<p>&nbsp;</p>';
		print '<p>The OneClick configuration <strong>' . $name . '</strong> has been sucessfully Changed.</p>';
		print '<p>You can go to the <a href="index.php?mode=newOneClick">OneClick List</a> to download an instance for your platform.</p>';
	} else {
		print '<p>&nbsp;</p>';
		print '<h2>OneClick Configurator Change FAILED!!!!</h2>';
		print '<p>&nbsp;</p>';
		print '<p>The OneClick configuration <strong>' . $name . '</strong> has not been changed.' . $error1 . '</p>';
		print '<p>You can go to the <a href="index.php?mode=newOneClick">OneClick List</a> to view all your instances.</p>';
	}
	//dbDisconnect();
}

require_once "conf.php";

function deleteDir($dirPath) {
    if (! is_dir($dirPath)) {
        throw new InvalidArgumentException('$dirPath must be a directory');
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            deleteDir($file);
        } else {
            unlink($file);
        }
    }
    rmdir($dirPath);
}

function recurse_copy($src,$dst) { 
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . '/' . $file) ) { 
                recurse_copy($src . '/' . $file,$dst . '/' . $file); 
            } 
            else { 
                copy($src . '/' . $file,$dst . '/' . $file); 
            } 
        } 
    } 
    closedir($dir); 
}

function downloadOneClick() {
	//global $user;
	$oneClickId = processInputVar("oneclickid", ARG_NUMERIC);
	
	$configFileContent = "vclURL=".XMLRPCURL."\n";
	$configFileContent .= "oneClickID={$oneClickId}\n";
	
	
	$oneClickParams = array();
	if($oneClickId > 0) {
		$query = "SELECT * FROM oneclick WHERE id={$oneClickId}";
		
		$qh = doQuery($query, 101);
		if(!$qh)
			exit;
		$oneClickParams = mysql_fetch_assoc($qh);
	}
	
	
	$package = dirname(__FILE__)."/../package";
	$random = rand(1,9999999);
	if($_POST['platform']=="android") {
		$tmpFolder = "{$package}/temp/and{$random}";
		$tmpFile = "$tmpFolder.apk";
		$configFile = "{$tmpFolder}/res/raw/config";
		$keystore = "{$package}/vcl.keystore";
		
		//Make a working copy of the binaries
		recurse_copy("{$package}/android/", "{$tmpFolder}/");
		//Add Configuration file
		$fh = fopen($configFile, 'w');
		fwrite($fh, $configFileContent);
		fclose($fh);
		
		//To to where the sources are 
		chdir($tmpFolder);
		//Zip sources into apk
		exec('zip -r '.$tmpFile.' *');
		//Sign the file
		exec("jarsigner -verbose -keystore {$keystore} -storepass test123 {$tmpFile} vcl");
		
		ob_clean();   // discard any data in the output buffer (if possible)
		//flush();      // flush headers (if possible)
		
		//Send the file
		header("Content-type: application/vnd.android.package-archive");
		header("Content-Disposition: attachment; filename=".str_replace(" ", "", $oneClickParams['name']).".apk");
		
		readfile($tmpFile);
		
		//Cleanup
		unlink($tmpFile);
		deleteDir($tmpFolder);
		exit;
		
	}
	else if($_POST['platform']=="linux") {
		$tmpFolder = "{$package}/temp/lin{$random}";
		$tmpFile = "$tmpFolder.jar";
		$configFile = "{$tmpFolder}/edu/ncsu/vcl/OneClick/App/config";
		$keystore = "{$package}/vcl.keystore";
		
		//Make a working copy of the binaries
		recurse_copy("{$package}/linux/", "{$tmpFolder}/");
		//Add Configuration file
		$fh = fopen($configFile, 'w');
		fwrite($fh, $configFileContent);
		fclose($fh);
		
		//To to where the sources are 
		chdir($tmpFolder);
		//Zip sources into jar
		exec('zip -r '.$tmpFile.' *');
		//Sign the file
		exec("jarsigner -verbose -keystore {$keystore} -storepass test123 {$tmpFile} vcl");
		
		//Send the file
		header("Content-type: application/java-archive");
		header("Content-Disposition: attachment; filename=".str_replace(" ", "", $oneClickParams['name']).".jar");
		ob_clean();   // discard any data in the output buffer (if possible)
		flush();      // flush headers (if possible)
		readfile($tmpFile);
		
		//Cleanup
		unlink($tmpFile);
		deleteDir($tmpFolder);
		
		exit;
	}
	else if($_POST['platform']=="win") {
		$tmpFolder = "{$package}/temp/win{$random}";
		$tmpFile = "{$tmpFolder}.zip";
		$configFile = "{$tmpFolder}/pack/config";
		$setupFile = "{$tmpFolder}/pack/~zipinst~.zic";
		
		//Make a working copy of the binaries
		recurse_copy("{$package}/windows/", "{$tmpFolder}/");
		//Add Configuration file
		$fh = fopen($configFile, 'w');
		fwrite($fh, $configFileContent);
		fclose($fh);
		//Add OneClick Parameter to Setup config
		$fh = fopen($setupFile, 'a');
		fwrite($fh, "ProductName={$oneClickParams['name']}\r\n");
		fclose($fh);
		
		//To to where the sources are 
		chdir($tmpFolder."/pack");
		//Zip sources into apk
		exec('zip -r oneclick.zip *');
		exec("cp {$tmpFolder}/pack/oneclick.zip {$tmpFolder}/dist/");
		
		//To to where the sources are 
		chdir($tmpFolder."/dist");
		//Zip sources into apk
		exec('zip -r '.$tmpFile.' *');
		
		//Send the file
		header("Content-type: application/zip");
		header("Content-Disposition: attachment; filename=".str_replace(" ", "", $oneClickParams['name']).".zip");
		
		ob_clean();   // discard any data in the output buffer (if possible)
		flush();      // flush headers (if possible)
		
		readfile($tmpFile);
		
		//Cleanup
		unlink($tmpFile);
		deleteDir($tmpFolder);
		
		exit;
	}
	else {
		print "Platform not supported !!";
	}
	
}

