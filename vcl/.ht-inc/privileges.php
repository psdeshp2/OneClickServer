<?php
/*
  Licensed to the Apache Software Foundation (ASF) under one or more
  contributor license agreements.  See the NOTICE file distributed with
  this work for additional information regarding copyright ownership.
  The ASF licenses this file to You under the Apache License, Version 2.0
  (the "License"); you may not use this file except in compliance with
  the License.  You may obtain a copy of the License at

      http://www.apache.org/licenses/LICENSE-2.0

  Unless required by applicable law or agreed to in writing, software
  distributed under the License is distributed on an "AS IS" BASIS,
  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
  See the License for the specific language governing permissions and
  limitations under the License.
*/

/**
 * \file
 */

/// signifies an error with the submitted new node name
define("NEWNODENAMEERR", 1);
/// signifies an error with the submitted new user id
define("NEWUSERERR", 1);
/// signifies no privs were submitted with the new user
define("ADDUSERNOPRIVS", 1 << 1);

////////////////////////////////////////////////////////////////////////////////
///
/// \fn viewNodes()
///
/// \brief prints a node privilege tree and the privliges at the node
///
////////////////////////////////////////////////////////////////////////////////
function viewNodes() {
	global $user;
	if(! empty($_COOKIE["VCLACTIVENODE"]) &&
		nodeExists($_COOKIE['VCLACTIVENODE']))
		$activeNode = $_COOKIE["VCLACTIVENODE"];
	else {
		$topNodes = getChildNodes();
		if(! count($topNodes))
			abort(53);
		$keys = array_keys($topNodes);
		$defaultActive = array_shift($keys);
		$activeNode = $defaultActive;
	}

	$hasNodeAdmin = checkUserHasPriv("nodeAdmin", $user["id"], $activeNode);
	$hasManagePerms = checkUserHasPerm('Manage Additional User Group Permissions');

	# tree
	if($hasManagePerms) {
		print "<div id=\"mainTabContainer\" dojoType=\"dijit.layout.TabContainer\"\n";
		print "     style=\"width:750px;height:600px\">\n";
		print "<div id=\"privtreetab\" dojoType=\"dijit.layout.ContentPane\" title=\"Privilege Tree\">\n";
	}
	print "<H2>Privilege Tree</H2>\n";
	$cont = addContinuationsEntry('JSONprivnodelist');
	print "<div dojoType=\"dojo.data.ItemFileWriteStore\" url=\"" . BASEURL . SCRIPT . "?continuation=$cont\" jsid=\"nodestore\" id=\"nodestore\"></div>\n";
	print "<div class=privtreediv>\n";
	print "<div dojoType=\"dijit.Tree\" store=\"nodestore\" showRoot=\"false\" id=privtree>\n";
	#print "  <script type=\"dojo/method\" event=\"getIconClass\" args=\"item, opened\">\n";
	##print "    return getTreeIcon(item, opened);\n";
	#print "    return '';\n";
	#print "  </script>\n";
	#print "  <script type=\"dojo/method\" event=\"onClick\" args=\"item, node\">\n";
	#print "    nodeSelect(item, node);\n";
	#print "  </script>\n";
	print "  <script type=\"dojo/connect\" event=\"focusNode\" args=\"node\">\n";
	print "    nodeSelect(node);\n";
	print "  </script>\n";
	print "  <script type=\"dojo/method\" event=\"_onExpandoClick\" args=\"message\">\n";
	print "    var node = message.node;\n";
	print "    var addclass = 0;\n";
	print "    var focusid = node.tree.lastFocused.item.name;\n";
	print "    if(node.isExpanded){\n";
	print "      if(isChildFocused(focusid, node.item.children)) {\n";
	print "        this.focusNode(node);\n";
	print "        addclass = 1;\n";
	print "      }\n";
	print "      this._collapseNode(node);\n";
	print "    }else{\n";
	print "      this._expandNode(node);\n";
	print "    }\n";
	print "    if(addclass || node.item.name == focusid)\n";
	print "      dojo.addClass(node.labelNode, 'privtreeselected');\n";
	print "  </script>\n";
	print "  <script type=\"dojo/connect\" event=\"startup\" args=\"item\">\n";
	print "    focusFirstNode($activeNode);\n";
	print "  </script>\n";
	print "</div>\n";
	print "</div>\n";
	print "<div id=treebuttons>\n";
	if($hasNodeAdmin) {
		print "<TABLE summary=\"\" cellspacing=\"\" cellpadding=\"\">\n";
		print "  <TR valign=top>\n";
		print "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>\n";
		print "    <button id=addNodeBtn dojoType=\"dijit.form.Button\">\n";
		print "      Add Child\n";
		print "	    <script type=\"dojo/method\" event=onClick>\n";
		print "        showPrivPane('addNodePane');\n";
		print "        return false;\n";
		print "      </script>\n";
		print "    </button>\n";
		print "    </FORM></TD>\n";
		print "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>\n";
		print "    <button id=deleteNodeBtn dojoType=\"dijit.form.Button\">\n";
		print "      Delete Node and Children\n";
		print "	    <script type=\"dojo/method\" event=onClick>\n";
		print "        dijit.byId('deleteDialog').show();\n";
		print "        return false;\n";
		print "      </script>\n";
		print "    </button>\n";
		print "    </FORM></TD>\n";
		print "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>\n";
		print "    <button id=renameNodeBtn dojoType=\"dijit.form.Button\">\n";
		print "      Rename Node\n";
		print "	    <script type=\"dojo/method\" event=onClick>\n";
		print "        dijit.byId('renameDialog').show();\n";
		print "        return false;\n";
		print "      </script>\n";
		print "    </button>\n";
		print "    </FORM></TD>\n";
		print "    <td></td>\n";
		print "  </TR>\n";
		print "</TABLE>\n";
	}
	print "</div>\n";
	$cont = addContinuationsEntry('selectNode');
	print "<INPUT type=hidden id=nodecont value=\"$cont\">\n";

	# privileges
	print "<H2>Privileges at Selected Node</H2>\n";
	$node = $activeNode;

	$nodeInfo = getNodeInfo($node);
	$privs = getNodePrivileges($node);
	$cascadePrivs = getNodeCascadePrivileges($node);
	$usertypes = getTypes("users");
	$i = 0;
	$hasUserGrant = checkUserHasPriv("userGrant", $user["id"], $node,
	                                 $privs, $cascadePrivs);
	$hasResourceGrant = checkUserHasPriv("resourceGrant", $user["id"],
	                                     $node, $privs, $cascadePrivs);
	
	print "<div id=nodePerms>\n";

	# users
	print "<A name=\"users\"></a>\n";
	print "<div id=usersDiv>\n";
	print "<H3>Users</H3>\n";
	print "<FORM id=usersform action=\"" . BASEURL . SCRIPT . "#users\" method=post>\n";
	$users = array();
	if(count($privs["users"]) || count($cascadePrivs["users"])) {
		print "<TABLE border=1 summary=\"\">\n";
		print "  <TR>\n";
		print "    <TD></TD>\n";
		print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
		print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
		foreach($usertypes["users"] as $type) {
			$img = getImageText($type);
			print "    <TD>$img</TD>\n";
		}
		print "  </TR>\n";
		$users = array_unique(array_merge(array_keys($privs["users"]), 
		                      array_keys($cascadePrivs["users"])));
		sort($users);
		foreach($users as $_user) {
			printUserPrivRow($_user, $i, $privs["users"], $usertypes["users"],
			                 $cascadePrivs["users"], 'user', ! $hasUserGrant);
			$i++;
		}
		print "</TABLE>\n";
		print "<div id=lastUserNum class=hidden>" . ($i - 1) . "</div>\n";
		if($hasUserGrant) {
			$cont = addContinuationsEntry('AJchangeUserPrivs');
			print "<INPUT type=hidden id=changeuserprivcont value=\"$cont\">\n";
		}
	}
	else {
		print "There are no user privileges at the selected node.<br>\n";
	}
	if($hasUserGrant) {
		print "<button id=addUserBtn dojoType=\"dijit.form.Button\">\n";
		print "  Add User\n";
		print "	<script type=\"dojo/method\" event=onClick>\n";
		print "    showPrivPane('addUserPane');\n";
		print "    return false;\n";
		print "  </script>\n";
		print "</button>\n";
	}
	print "</FORM>\n";
	print "</div>\n";

	# groups
	print "<A name=\"groups\"></a>\n";
	print "<div id=usergroupsDiv>\n";
	print "<H3>User Groups</H3>\n";
	if(count($privs["usergroups"]) || count($cascadePrivs["usergroups"])) {
		print "<FORM action=\"" . BASEURL . SCRIPT . "#groups\" method=post>\n";
		print "<div id=firstUserGroupNum class=hidden>$i</div>";
		print "<TABLE border=1 summary=\"\">\n";
		print "  <TR>\n";
		print "    <TD></TD>\n";
		print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
		print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
		foreach($usertypes["users"] as $type) {
			$img = getImageText($type);
			print "    <TH>$img</TH>\n";
		}
		print "  </TR>\n";
		$groups = array_unique(array_merge(array_keys($privs["usergroups"]), 
		                      array_keys($cascadePrivs["usergroups"])));
		sort($groups);
		foreach($groups as $group) {
			printUserPrivRow($group, $i, $privs["usergroups"], $usertypes["users"],
			                $cascadePrivs["usergroups"], 'group', ! $hasUserGrant);
			$i++;
		}
		print "</TABLE>\n";
		print "<div id=lastUserGroupNum class=hidden>" . ($i - 1) . "</div>";
		if($hasUserGrant) {
			$cont = addContinuationsEntry('AJchangeUserGroupPrivs');
			print "<INPUT type=hidden id=changeusergroupprivcont value=\"$cont\">\n";
		}
		$cont = addContinuationsEntry('jsonGetUserGroupMembers');
		print "<INPUT type=hidden id=ugmcont value=\"$cont\">\n";
	}
	else {
		print "There are no user group privileges at the selected node.<br>\n";
		$groups = array();
	}
	if($hasUserGrant) {
		print "<button id=addGroupBtn dojoType=\"dijit.form.Button\">\n";
		print "  Add Group\n";
		print "	<script type=\"dojo/method\" event=onClick>\n";
		print "    showPrivPane('addUserGroupPane');\n";
		print "    return false;\n";
		print "  </script>\n";
		print "</button>\n";
	}
	print "</FORM>\n";
	print "</div>\n";

	# resources
	$resourcetypes = getResourcePrivs();
	print "<A name=\"resources\"></a>\n";
	print "<div id=resourcesDiv>\n";
	print "<H3>Resources</H3>\n";
	print "<FORM id=resourceForm action=\"" . BASEURL . SCRIPT . "#resources\" method=post>\n";
	if(count($privs["resources"]) || count($cascadePrivs["resources"])) {
		print "<TABLE border=1 summary=\"\">\n";
		print "  <TR>\n";
		print "    <TH>Group<br>Name</TH>\n";
		print "    <TH>Group<br>Type</TH>\n";
		print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
		print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
		foreach($resourcetypes as $type) {
			if($type == 'block' || $type == 'cascade')
				continue;
			$img = getImageText("$type");
			print "    <TH>$img</TH>\n";
		}
		print "  </TR>\n";
		$resources = array_unique(array_merge(array_keys($privs["resources"]), 
		                          array_keys($cascadePrivs["resources"])));
		sort($resources);
		$resourcegroups = getResourceGroups();
		$resgroupmembers = getResourceGroupMembers();
		foreach($resources as $resource) {
			$data = getResourcePrivRowHTML($resource, $i, $privs["resources"],
			                               $resourcetypes, $resourcegroups,
			                               $resgroupmembers, $cascadePrivs["resources"],
			                               ! $hasResourceGrant);
			print $data['html'];
			print "<script language=\"Javascript\">\n";
			print "dojo.addOnLoad(function () {setTimeout(\"{$data['javascript']}\", 500)});\n";
			print "</script>\n";
			$i++;
		}
		print "</TABLE>\n";
		if($hasResourceGrant) {
			$cont = addContinuationsEntry('AJchangeResourcePrivs');
			print "<INPUT type=hidden id=changeresourceprivcont value=\"$cont\">\n";
		}
		$cont = addContinuationsEntry('jsonGetResourceGroupMembers');
		print "<INPUT type=hidden id=rgmcont value=\"$cont\">\n";
	}
	else {
		print "There are no resource group privileges at the selected node.<br>\n";
		$resources = array();
	}
	if($hasResourceGrant) {
		print "<button id=addResourceBtn dojoType=\"dijit.form.Button\">\n";
		print "  Add Resource Group\n";
		print "	<script type=\"dojo/method\" event=onClick>\n";
		print "    showPrivPane('addResourceGroupPane');\n";
		print "    return false;\n";
		print "  </script>\n";
		print "</button>\n";
	}
	print "</FORM>\n";
	print "</div>\n";
	print "</div>\n";

	# ----------------------------- dialogs ----------------------------
	print "<div dojoType=dijit.Dialog\n";
	print "      id=addUserPane\n";
	print "      title=\"Add User Permission\"\n";
	print "      duration=250\n";
	print "      draggable=true>\n";
	print "	  <script type=\"dojo/connect\" event=onCancel>\n";
	print "      addUserPaneHide();\n";
	print "    </script>\n";
	print "<H2>Add User</H2>\n";
	print "<div id=addPaneNodeName></div>\n";
	print "<TABLE border=1 summary=\"\">\n";
	print "  <TR>\n";
	print "    <TD></TD>\n";
	print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
	print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
	foreach($usertypes["users"] as $type) {
		$img = getImageText($type);
		print "    <TD>$img</TD>\n";
	}
	print "  </TR>\n";
	print "  <TR>\n";
	print "    <TD><INPUT type=text id=newuser name=newuser size=15";
	print "></TD>\n";

	# block rights
	$count = count($usertypes) + 1;
	print "    <TD align=center bgcolor=gray><INPUT type=checkbox ";
	print "dojoType=dijit.form.CheckBox id=blockchk name=block></TD>\n";

	#cascade rights
	print "    <TD align=center bgcolor=\"#008000\" id=usercell0:0>";
	print "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=userck0:0 ";
	print "name=cascade></TD>\n";

	# normal rights
	$j = 1;
	foreach($usertypes["users"] as $type) {
		print "    <TD align=center id=usercell0:$j><INPUT type=checkbox ";
		print "dojoType=dijit.form.CheckBox name=\"$type\" id=userck0:$j></TD>\n";
		$j++;
	}
	print "  </TR>\n";
	print "</TABLE>\n";
	print "<div id=addUserPrivStatus></div>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button id=submitAddUserBtn dojoType=\"dijit.form.Button\">\n";
	print "    Submit New User\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      submitAddUser();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button id=cancelAddUserBtn dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      addUserPaneHide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitAddUserPriv');
	print "<INPUT type=hidden id=addusercont value=\"$cont\">\n";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog\n";
	print "      id=addUserGroupPane\n";
	print "      title=\"Add User Group Permission\"\n";
	print "      duration=250\n";
	print "      draggable=true>\n";
	print "	  <script type=\"dojo/connect\" event=onCancel>\n";
	print "      addUserGroupPaneHide();\n";
	print "    </script>\n";
	print "<H2>Add User Group</H2>\n";
	print "<div id=addGroupPaneNodeName></div>\n";
	print "<TABLE border=1 summary=\"\">\n";
	print "  <TR>\n";
	print "    <TD></TD>\n";
	print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
	print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
	foreach($usertypes["users"] as $type) {
		$img = getImageText($type);
		print "    <TD>$img</TD>\n";
	}
	print "  </TR>\n";
	print "  <TR>\n";
	print "    <TD>\n";
	# FIXME should $groups be only the user's groups?
	$groups = getUserGroups(0, $user['affiliationid']);
	printSelectInput("newgroupid", $groups, -1, 0, 0, 'newgroupid');
	print "    </TD>\n";

	# block rights
	print "    <TD align=center bgcolor=gray><INPUT type=checkbox ";
	print "dojoType=dijit.form.CheckBox id=blockgrpchk name=blockgrp></TD>\n";

	#cascade rights
	print "    <TD align=center bgcolor=\"#008000\" id=grpcell0:0>";
	print "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=usergrpck0:0 ";
	print "name=cascadegrp></TD>\n";

	# normal rights
	$j = 1;
	foreach($usertypes["users"] as $type) {
		print "    <TD align=center id=usergrpcell0:$j><INPUT type=checkbox ";
		print "dojoType=dijit.form.CheckBox name=\"$type\" id=usergrpck0:$j></TD>\n";
		$j++;
	}
	print "  </TR>\n";
	print "</TABLE>\n";
	print "<div id=addUserGroupPrivStatus></div>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button id=submitAddGroupBtn dojoType=\"dijit.form.Button\">\n";
	print "    Submit New User Group\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      submitAddUserGroup();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button id=cancelAddGroupBtn dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      addUserGroupPaneHide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitAddUserGroupPriv');
	print "<INPUT type=hidden id=addusergroupcont value=\"$cont\">\n";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog\n";
	print "      id=addResourceGroupPane\n";
	print "      title=\"Add Resource Group Permission\"\n";
	print "      duration=250\n";
	print "      draggable=true>\n";
	print "	  <script type=\"dojo/connect\" event=onCancel>\n";
	print "      addResourceGroupPaneHide();\n";
	print "    </script>\n";
	print "<H2>Add Resource Group</H2>\n";
	print "<div id=addResourceGroupPaneNodeName></div>\n";
	print "<TABLE border=1 summary=\"\">\n";
	print "  <TR>\n";
	print "    <TD></TD>\n";
	print "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>\n";
	print "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>\n";
	foreach($resourcetypes as $type) {
		if($type == 'block' || $type == 'cascade')
			continue;
		$img = getImageText("$type");
		print "    <TH>$img</TH>\n";
	}
	print "  </TR>\n";
	print "  <TR>\n";
	print "    <TD>\n";
	$resources = array();
	$privs = array("computerAdmin","mgmtNodeAdmin",  "imageAdmin", "scheduleAdmin");
	$resourcesgroups = getUserResources($privs, array("manageGroup"), 1);
	foreach(array_keys($resourcesgroups) as $type) {
		foreach($resourcesgroups[$type] as $id => $group) {
			$resources[$id] = $type . "/" . $group;
		}
	}
	printSelectInput("newresourcegroupid", $resources, -1, 0, 0, 'newresourcegroupid');
	print "    </TD>\n";

	# block rights
	print "    <TD align=center bgcolor=gray><INPUT type=checkbox ";
	print "dojoType=dijit.form.CheckBox id=blockresgrpck name=blockresgrp></TD>\n";

	#cascade rights
	print "    <TD align=center bgcolor=\"#008000\" id=resgrpcell0:0>";
	print "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=resgrpck0:0 ";
	print "name=cascaderesgrp></TD>\n";

	# normal rights
	$i = 1;
	foreach($resourcetypes as $type) {
		if($type == 'block' || $type == 'cascade')
			continue;
		print "    <TD align=center id=resgrpcell0:$i><INPUT type=checkbox ";
		print "dojoType=dijit.form.CheckBox name=$type id=resgrpck0:$i></TD>\n";
		$i++;
	}
	print "  </TR>\n";
	print "</TABLE>\n";
	print "<div id=addResourceGroupPrivStatus></div>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button dojoType=\"dijit.form.Button\">\n";
	print "    Submit New Resource Group\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      submitAddResourceGroup();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      addResourceGroupPaneHide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitAddResourcePriv');
	print "<INPUT type=hidden id=addresourcegroupcont value=\"$cont\">\n";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog\n";
	print "     id=addNodePane\n";
	print "     title=\"Add Child Node\"\n";
	print "     duration=250\n";
	print "     draggable=true>\n";
	print "<H2>Add Child Node</H2>\n";
	print "<div id=addChildNodeName></div>\n";
	print "<strong>New Node:</strong>\n";
	print "<input type=text id=childNodeName dojoType=dijit.form.TextBox>\n";
	print "	<script type=\"dojo/connect\" event=onKeyPress args=\"e\">\n";
	print "    if(e.keyCode == dojo.keys.ENTER) {\n";
	print "      submitAddChildNode();\n";
	print "    }\n";
	print "  </script>\n";
	print "</input>\n";
	print "<div id=addChildNodeStatus></div>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button id=submitAddNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Create Child\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      submitAddChildNode();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button id=cancelAddNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      dojo.byId('childNodeName').value = '';\n";
	print "      dojo.byId('addChildNodeStatus').innerHTML = '';\n";
	print "      dijit.byId('addNodePane').hide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitAddChildNode');
	print "<INPUT type=hidden id=addchildcont value=\"$cont\"\n>";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog\n";
	print "     id=deleteDialog\n";
	print "     title=\"Delete Node(s)\"\n";
	print "     duration=250\n";
	print "     draggable=true>\n";
	print "Delete the following node and all of its children?<br><br>\n";
	print "<div id=deleteNodeName></div><br>\n";
	print "<div align=center>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button id=submitDeleteNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Delete Nodes\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      deleteNodes();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button id=cancelDeleteNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      dijit.byId('deleteDialog').hide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitDeleteNode');
	print "<INPUT type=hidden id=delchildcont value=\"$cont\"\n>";
	print "</div>\n";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog\n";
	print "     id=renameDialog\n";
	print "     title=\"Rename Node\"\n";
	print "     duration=250\n";
	print "     draggable=true>\n";
	print "Enter a new name for the selected node:<br><br>\n";
	print "<div id=renameNodeName></div><br>\n";
	print "<strong>New Name:</strong>\n";
	print "<input type=text id=newNodeName dojoType=dijit.form.TextBox>\n";
	print "	<script type=\"dojo/connect\" event=onKeyPress args=\"e\">\n";
	print "    if(e.keyCode == dojo.keys.ENTER) {\n";
	print "      renameNode();\n";
	print "    }\n";
	print "  </script>\n";
	print "</input>\n";
	print "<div id=renameNodeStatus></div>\n";
	print "<div align=center>\n";
	print "<TABLE summary=\"\"><TR>\n";
	print "<TD>\n";
	print "  <button id=submitRenameNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Rename Node\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      renameNode();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "<TD>\n";
	print "  <button id=cancelRenameNodeBtn dojoType=\"dijit.form.Button\">\n";
	print "    Cancel\n";
	print "	  <script type=\"dojo/method\" event=onClick>\n";
	print "      dijit.byId('renameDialog').hide();\n";
	print "    </script>\n";
	print "  </button>\n";
	print "</TD>\n";
	print "</TR></TABLE>\n";
	$cont = addContinuationsEntry('AJsubmitRenameNode');
	print "<INPUT type=hidden id=renamecont value=\"$cont\"\n>";
	print "</div>\n";
	print "</div>\n";

	print "<div dojoType=dijit.Dialog id=workingDialog duration=250 refocus=False>\n";
	print "Loading...\n";
	print "	<script type=\"dojo/connect\" event=_setup>\n";
	print "    dojo.addClass(dijit.byId('workingDialog').titleBar, 'hidden');\n";
	print "  </script>\n";
	print "</div>\n";
	if(! $hasManagePerms)
		return;
	print "</div>\n"; # end privtree tab

	print "<div id=\"userpermtab\" dojoType=\"dijit.layout.ContentPane\" title=\"Additional User Permissions\">\n";
	print "<h2>Additional User Group Permissions</h2>\n";
	print "There are additional permisssions that can be assigned to user<br>\n";
	print "groups that are not specific to any nodes in the privilege tree.<br>\n";
	print "Use this portion of the site to manage those permissions.<br><br>\n";
	printSelectInput("editusergroupid", $groups, -1, 0, 0, 'editusergroupid', 'onChange="hideUserGroupPrivs();"');
	$cont = addContinuationsEntry('AJpermSelectUserGroup');
	print "<button dojoType=\"dijit.form.Button\">\n";
	print "	Manage User Group Permissions\n";
	print "	<script type=\"dojo/method\" event=onClick>\n";
	print "		selectUserGroup('$cont');\n";
	print "	</script>\n";
	print "</button>\n";
	print "<div id=\"extrapermsdiv\">\n";
	print "<table summary=\"\">\n";
	print "<tr>\n";
	print "<td nowrap>\n";
	print "<div id=\"usergroupprivs\" class=\"groupprivshidden\">\n";
	$privtypes = getUserGroupPrivTypes();
	foreach($privtypes as $id => $type) {
		print "<span onMouseOver=\"showUserGroupPrivHelp('{$type['help']}', $id);\" \n";
		print "onMouseOut=\"clearUserGroupPrivHelp($id);\" id=\"grouptypespan$id\">\n";
		print "<input id=\"grouptype$id\" dojoType=\"dijit.form.CheckBox\" ";
		print "value=\"1\" name=\"$id\"><label for=\"grouptype$id\">{$type['name']}";
		print "</label></span><br>\n";
	}
	print "</div>\n";
	print "</td>\n";
	print "<td id=\"groupprivhelpcell\">\n";
	print "<fieldset style=\"height: 100%\";>\n";
	print "<legend>Permission Description</legend>\n";
	print "<div id=\"groupprivhelp\"></div>\n";
	print "</fieldset>\n";
	print "</td>\n";
	print "</tr>\n";
	print "</table><br><br>\n";
	print "Copy permissions from user group: ";
	printSelectInput("copyusergroupid", $groups, -1, 0, 0, 'copyusergroupid');
	$cont = addContinuationsEntry('AJpermSelectUserGroup');
	print "<button dojoType=\"dijit.form.Button\" id=\"usergroupcopyprivsbtn\" disabled>\n";
	print "	Copy Permissions\n";
	print "	<script type=\"dojo/method\" event=onClick>\n";
	print "		copyUserGroupPrivs('$cont');\n";
	print "	</script>\n";
	print "</button><br><br>\n";
	$cont = addContinuationsEntry('AJsaveUserGroupPrivs');
	print "<button dojoType=\"dijit.form.Button\" id=\"usergroupsaveprivsbtn\" disabled>\n";
	print "	Save Selected Permissions\n";
	print "	<script type=\"dojo/method\" event=onClick>\n";
	print "		saveUserGroupPrivs('$cont');\n";
	print "	</script>\n";
	print "</button><br>\n";
	print "<span id=\"userpermsubmitstatus\"></span>\n";
	print "</div>\n";
	print "</div>\n"; # end userperm tab

	print "</div>\n"; # end tab container
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn selectNode()
///
/// \brief generates html for ajax update to privileges page when a node is
/// clicked
///
////////////////////////////////////////////////////////////////////////////////
function selectNode() {
	global $user;
	$node = processInputVar("node", ARG_NUMERIC);
	if(empty($node))
		return;
	$return = "";
	$text = "";
	$js = "";
	$privs = getNodePrivileges($node);
	$cascadePrivs = getNodeCascadePrivileges($node);
	$usertypes = getTypes("users");
	$i = 0;
	$hasUserGrant = checkUserHasPriv("userGrant", $user["id"], $node,
	                                 $privs, $cascadePrivs);
	$hasResourceGrant = checkUserHasPriv("resourceGrant", $user["id"],
	                                     $node, $privs, $cascadePrivs);
	$hasNodeAdmin = checkUserHasPriv("nodeAdmin", $user["id"], $node, $privs,
	                                 $cascadePrivs);

	if($hasNodeAdmin) {
		$text .= "<TABLE>";
		$text .= "  <TR valign=top>";
		$text .= "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>";
		$text .= "    <button id=addNodeBtn dojoType=\"dijit.form.Button\">";
		$text .= "      Add Child";
		$text .= "	    <script type=\"dojo/method\" event=onClick>";
		$text .= "        showPrivPane(\"addNodePane\");";
		$text .= "        return false;";
		$text .= "      </script>";
		$text .= "    </button>";
		$text .= "    </FORM></TD>";
		$text .= "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>";
		$text .= "    <button id=deleteNodeBtn dojoType=\"dijit.form.Button\">";
		$text .= "      Delete Node and Children";
		$text .= "	    <script type=\"dojo/method\" event=onClick>";
		$text .= "        dijit.byId(\"deleteDialog\").show();";
		$text .= "        return false;";
		$text .= "      </script>";
		$text .= "    </button>";
		$text .= "    </FORM></TD>";
		$text .= "    <TD><FORM action=\"" . BASEURL . SCRIPT . "\" method=post>";
		$text .= "    <button id=renameNodeBtn dojoType=\"dijit.form.Button\">";
		$text .= "      Rename Node";
		$text .= "	    <script type=\"dojo/method\" event=onClick>";
		$text .= "        dijit.byId(\"renameDialog\").show();";
		$text .= "        return false;";
		$text .= "      </script>";
		$text .= "    </button>";
		$text .= "    </FORM></TD>";
		$text .= "  </TR>";
		$text .= "</TABLE>";
	}
	$return .= "if(dijit.byId('addNodeBtn')) dijit.byId('addNodeBtn').destroy();";
	$return .= "if(dijit.byId('deleteNodeBtn')) dijit.byId('deleteNodeBtn').destroy();";
	$return .= "if(dijit.byId('renameNodeBtn')) dijit.byId('renameNodeBtn').destroy();";
	$return .= setAttribute('treebuttons', 'innerHTML', $text);
	$return .= "AJdojoCreate('treebuttons');";

	# privileges
	$return .= "dojo.query('*', 'nodePerms').forEach(function(item){if(dijit.byId(item.id)) dijit.byId(item.id).destroy();});";
	$text = "";
	$text .= "<H3>Users</H3>";
	$users = array();
	if(count($privs["users"]) || count($cascadePrivs["users"])) {
		$text .= "<FORM id=usersform action=\"" . BASEURL . SCRIPT . "#users\" method=post>";
		$text .= "<TABLE border=1 summary=\"\">";
		$text .= "  <TR>";
		$text .= "    <TD></TD>";
		$text .= "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>";
		$text .= "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>";
		foreach($usertypes["users"] as $type) {
			$img = getImageText($type);
			$text .= "    <TD>$img</TD>";
		}
		$text .= "  </TR>";
		$users = array_unique(array_merge(array_keys($privs["users"]), 
		                      array_keys($cascadePrivs["users"])));
		sort($users);
		foreach($users as $_user) {
			$tmpArr = getUserPrivRowHTML($_user, $i, $privs["users"],
			                 $usertypes["users"], $cascadePrivs["users"], 'user',
			                 ! $hasUserGrant);
			$text .= $tmpArr['html'];
			$js .= $tmpArr['javascript'];
			$i++;
		}
		$text .= "</TABLE>";
		$text .= "<div id=lastUserNum class=hidden>" . ($i - 1) . "</div>";
		if($hasUserGrant) {
			$cont = addContinuationsEntry('AJchangeUserPrivs');
			$text .= "<INPUT type=hidden id=changeuserprivcont value=\"$cont\">";
		}
	}
	else {
		$text .= "There are no user privileges at the selected node.<br>";
	}
	if($hasUserGrant) {
		$text .= "<button id=addUserBtn dojoType=\"dijit.form.Button\">";
		$text .= "  Add User";
		$text .= "  <script type=\"dojo/method\" event=onClick>";
		$text .= "    showPrivPane(\"addUserPane\");";
		$text .= "    return false;";
		$text .= "  </script>";
		$text .= "</button>";
	}
	$text .= "</FORM>";
	$return .= setAttribute('usersDiv', 'innerHTML', $text);
	$return .= "AJdojoCreate('usersDiv');";

	# groups
	$text = "";
	$text .= "<H3>User Groups</H3>";
	if(count($privs["usergroups"]) || count($cascadePrivs["usergroups"])) {
		$text .= "<FORM action=\"" . BASEURL . SCRIPT . "#groups\" method=post>";
		$text .= "<div id=firstUserGroupNum class=hidden>$i</div>";
		$text .= "<TABLE border=1 summary=\"\">";
		$text .= "  <TR>";
		$text .= "    <TD></TD>";
		$text .= "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>";
		$text .= "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>";
		foreach($usertypes["users"] as $type) {
			$img = getImageText($type);
			$text .= "    <TH>$img</TH>";
		}
		$text .= "  </TR>";
		$groups = array_unique(array_merge(array_keys($privs["usergroups"]), 
		                      array_keys($cascadePrivs["usergroups"])));
		sort($groups);
		foreach($groups as $group) {
			$tmpArr = getUserPrivRowHTML($group, $i, $privs["usergroups"],
			                  $usertypes["users"], $cascadePrivs["usergroups"],
			                  'group', ! $hasUserGrant);
			$text .= $tmpArr['html'];
			$js .= $tmpArr['javascript'];
			$i++;
		}
		$text .= "</TABLE>";
		$text .= "<div id=lastUserGroupNum class=hidden>" . ($i - 1) . "</div>";
		if($hasUserGrant) {
			$cont = addContinuationsEntry('AJchangeUserGroupPrivs');
			$text .= "<INPUT type=hidden id=changeusergroupprivcont value=\"$cont\">";
		}
		$cont = addContinuationsEntry('jsonGetUserGroupMembers');
		$text .= "<INPUT type=hidden id=ugmcont value=\"$cont\">";
	}
	else {
		$text .= "There are no user group privileges at the selected node.<br>";
		$groups = array();
	}
	if($hasUserGrant) {
		$text .= "<button id=addGroupBtn dojoType=\"dijit.form.Button\">";
		$text .= "  Add Group";
		$text .= "	<script type=\"dojo/method\" event=onClick>";
		$text .= "    showPrivPane(\"addUserGroupPane\");";
		$text .= "    return false;";
		$text .= "  </script>";
		$text .= "</button>";
	}
	$text .= "</FORM>";
	$return .= setAttribute('usergroupsDiv', 'innerHTML', $text);
	$return .= "AJdojoCreate('usergroupsDiv');";

	# resources
	$text = "";
	$resourcetypes = getResourcePrivs();
	$text .= "<H3>Resources</H3>";
	$text .= "<FORM id=resourceForm action=\"" . BASEURL . SCRIPT . "#resources\" method=post>";
	if(count($privs["resources"]) || count($cascadePrivs["resources"])) {
		$text .= "<TABLE border=1 summary=\"\">";
		$text .= "  <TR>";
		$text .= "    <TH>Group<br>Name</TH>";
		$text .= "    <TH>Group<br>Type</TH>";
		$text .= "    <TH bgcolor=gray style=\"color: black;\">Block<br>Cascaded<br>Rights</TH>";
		$text .= "    <TH bgcolor=\"#008000\" style=\"color: black;\">Cascade<br>to Child<br>Nodes</TH>";
		foreach($resourcetypes as $type) {
			if($type == 'block' || $type == 'cascade')
				continue;
			$img = getImageText("$type");
			$text .= "    <TH>$img</TH>";
		}
		$text .= "  </TR>";
		$resources = array_unique(array_merge(array_keys($privs["resources"]), 
		                          array_keys($cascadePrivs["resources"])));
		sort($resources);
		$resourcegroups = getResourceGroups();
		$resgroupmembers = getResourceGroupMembers();
		foreach($resources as $resource) {
			$tmpArr = getResourcePrivRowHTML($resource, $i, $privs["resources"],
			          $resourcetypes, $resourcegroups, $resgroupmembers,
			          $cascadePrivs["resources"], ! $hasResourceGrant);
			$html = str_replace("\n", '', $tmpArr['html']);
			$html = str_replace("'", "\'", $html);
			$html = preg_replace("/>\s*</", "><", $html);
			$text .= $html;
			$js .= $tmpArr['javascript'];
			$i++;
		}
		$text .= "</TABLE>";
		if($hasResourceGrant) {
			$cont = addContinuationsEntry('AJchangeResourcePrivs');
			$text .= "<INPUT type=hidden id=changeresourceprivcont value=\"$cont\">";
		}
		$cont = addContinuationsEntry('jsonGetResourceGroupMembers');
		$text .= "<INPUT type=hidden id=rgmcont value=\"$cont\">";
	}
	else {
		$text .= "There are no resource group privileges at the selected node.<br>";
		$resources = array();
	}
	if($hasResourceGrant) {
		$text .= "<button id=addResourceBtn dojoType=\"dijit.form.Button\">";
		$text .= "  Add Resource Group";
		$text .= "	<script type=\"dojo/method\" event=onClick>";
		$text .= "    showPrivPane(\"addResourceGroupPane\");";
		$text .= "    return false;";
		$text .= "  </script>";
		$text .= "</button>";
	}
	$text .= "</FORM>";
	$return .= setAttribute('resourcesDiv', 'innerHTML', $text);
	$return .= "AJdojoCreate('resourcesDiv');";

	print $return;
	print $js;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn JSONprivnodelist()
///
/// \brief prints a json list of privilege nodes
///
////////////////////////////////////////////////////////////////////////////////
function JSONprivnodelist() {
	$nodes = getChildNodes();
	$data = JSONprivnodelist2($nodes);
	header('Content-Type: text/json; charset=utf-8');
	$data = "{} && {label:'display',identifier:'name',items:[$data]}";
	print $data;
}


////////////////////////////////////////////////////////////////////////////////
///
/// \fn JSONprivnodelist2($nodelist)
///
/// \param $nodelist - an array of nodes as returned from getChildNodes
///
/// \return partial json data to build list for JSONprivnodelist
///
/// \brief sub function for JSONprivnodelist to help build json node data
///
////////////////////////////////////////////////////////////////////////////////
function JSONprivnodelist2($nodelist) {
	$data = '';
	foreach(array_keys($nodelist) as $id) {
		$data .= "{name:'$id', display:'{$nodelist[$id]['name']}' ";
		$children = getChildNodes($id);
		if(count($children))
			$data .= ", children: [ " . JSONprivnodelist2($children) . "]},";
		else
			$data .= "},";
	}
	$data = rtrim($data, ',');
	return $data;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitAddChildNode()
///
/// \brief processes input for adding a child node; if all is ok, adds node
/// to privnode table; checks to see if submitting user has nodeAdmin,
/// userGrant, and resourceGrant cascaded to the node; adds any of the privs
/// that aren't cascaded; calls viewNodes when finished
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitAddChildNode() {
	global $user;
	$parent = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("nodeAdmin", $user["id"], $parent)) {
		$text = "You do not have rights to add children to this node.";
		print "dojo.byId('childNodeName').value = ''; ";
		print "dijit.byId('addNodePane').hide(); ";
		print "alert('$text');";
		return;
	}
	$nodeInfo = getNodeInfo($parent);
	$newnode = processInputVar("newnode", ARG_STRING);
	if(! preg_match('/^[-A-Za-z0-9_. ]+$/', $newnode)) {
		$text = "You can only use letters, numbers, spaces,<br>"
		      . "dashes(-), dots(.), and underscores(_).";
		print "dojo.byId('addChildNodeStatus').innerHTML = '$text';";
		return;
	}

	# check to see if a node with the submitted name already exists
	$query = "SELECT id "
	       . "FROM privnode "
	       . "WHERE name = '$newnode' AND "
	       .       "parent = $parent";
	$qh = doQuery($query, 335);
	if(mysql_num_rows($qh)) {
		$text = "A node of that name already exists "
		      . "under " . $nodeInfo["name"];
		print "dojo.byId('addChildNodeStatus').innerHTML = '$text';";
		return;
	}
	$query = "INSERT INTO privnode "
	       .         "(parent, "
	       .         "name) "
	       . "VALUES "
	       .         "($parent, "
	       .         "'$newnode')";
	doQuery($query, 336);

	$qh = doQuery("SELECT LAST_INSERT_ID() FROM privnode", 101);
	if(! $row = mysql_fetch_row($qh))
		abort(101);
	$nodeid = $row[0];

	$privs = array();
	foreach(array("nodeAdmin", "userGrant", "resourceGrant") as $type) {
		if(! checkUserHasPriv($type, $user["id"], $nodeid))
			array_push($privs, $type);
	}
	if(count($privs))
		array_push($privs, "cascade");
	updateUserOrGroupPrivs($user["id"], $nodeid, $privs, array(), "user");
	print "addChildNode('$newnode', $nodeid);";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn nodeExists($node)
///
/// \param $node - the id of a node
///
/// \return 1 if exists, 0 if not
///
/// \brief checks to see if $node exists
///
////////////////////////////////////////////////////////////////////////////////
function nodeExists($node) {
	$query = "SELECT id FROM privnode WHERE id = $node";
	$qh = doQuery($query, 101);
	if(mysql_num_rows($qh))
		return 1;
	else
		return 0;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitDeleteNode()
///
/// \brief deletes a node and its children; calls viewNodes when finished
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitDeleteNode() {
	global $user;
	$activeNode = processInputVar("activeNode", ARG_NUMERIC);
	if(empty($activeNode))
		return;
	if(! checkUserHasPriv("nodeAdmin", $user["id"], $activeNode)) {
		$text = "You do not have rights to delete this node.";
		print "alert('$text');";
		return;
	}
	clearPrivCache();
	$nodes = recurseGetChildren($activeNode);
	$parents = getParentNodes($activeNode);
	$parent = $parents[0];
	array_push($nodes, $activeNode);
	$deleteNodes = implode(',', $nodes);
	$query = "DELETE FROM privnode "
	       . "WHERE id IN ($deleteNodes)";
	doQuery($query, 345);
	print "setSelectedPrivNode('$parent'); ";
	print "removeNodesFromTree('$deleteNodes'); ";
	print "dijit.byId('deleteDialog').hide(); ";
	print "var workingobj = dijit.byId('workingDialog'); ";
	print "dojo.connect(workingobj._fadeOut, 'onEnd', dijit.byId('deleteDialog'), 'hide'); ";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitRenameNode()
///
/// \brief deletes a node and its children; calls viewNodes when finished
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitRenameNode() {
	global $user;
	$activeNode = processInputVar("activeNode", ARG_NUMERIC);
	if(empty($activeNode))
		return;
	if(! checkUserHasPriv("nodeAdmin", $user["id"], $activeNode)) {
		$msg = "You do not have rights to rename this node.";
		$arr = array('error' => 1, 'message' => $msg);
		sendJSON($arr);
		return;
	}
	# check if node matching new name already exists at parent
	$newname = processInputVar('newname', ARG_STRING);
	$query = "SELECT id "
	       . "FROM privnode "
	       . "WHERE parent = (SELECT parent FROM privnode WHERE id = $activeNode) AND "
	       .       "name = '$newname'";
	$qh = doQuery($query, 101);
	if(mysql_num_rows($qh)) {
		$msg = "A sibling node of that name currently exists";
		$arr = array('error' => 2, 'message' => $msg);
		sendJSON($arr);
		return;
	}

	$query = "UPDATE privnode "
	       . "SET name = '$newname' " 
	       . "WHERE id = $activeNode";
	doQuery($query, 101);
	$arr = array('newname' => $newname, 'node' => $activeNode);
	sendJSON($arr);
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn userLookup()
///
/// \brief prints a page to display a user's privileges
///
////////////////////////////////////////////////////////////////////////////////
function userLookup() {
	global $user;
	$userid = processInputVar("userid", ARG_STRING);
	if(get_magic_quotes_gpc())
		$userid = stripslashes($userid);
	$affilid = processInputVar('affiliationid', ARG_NUMERIC, $user['affiliationid']);
	$force = processInputVar('force', ARG_NUMERIC, 0);
	print "<div align=center>\n";
	print "<H2>User Lookup</H2>\n";
	print "<FORM action=\"" . BASEURL . SCRIPT . "\" method=post>\n";
	print "<TABLE>\n";
	print "  <TR>\n";
	print "    <TH>Name (last, first) or User ID:</TH>\n";
	print "    <TD><INPUT type=text name=userid value=\"$userid\" size=25></TD>\n";
	if(checkUserHasPerm('User Lookup (global)')) {
		$affils = getAffiliations();
		print "    <TD>\n";
		print "@";
		printSelectInput("affiliationid", $affils, $affilid);
		print "    </TD>\n";
	}
	print "  </TR>\n";
	print "  <TR>\n";
	print "    <TD colspan=2>\n";
	print "      <input type=checkbox id=force name=force value=1>\n";
	print "      <label for=force>Attempt forcing an update from LDAP (User ID only)</label>\n";
	print "    </TD>\n";
	print "  </TR>\n";
	print "  <TR>\n";
	print "    <TD colspan=3 align=center><INPUT type=submit value=Submit>\n";
	print "  </TR>\n";
	print "</TABLE>\n";
	$cont = addContinuationsEntry('submitUserLookup');
	print "<INPUT type=hidden name=continuation value=\"$cont\">\n";
	print "</FORM><br>\n";
	if(! empty($userid)) {
		$esc_userid = mysql_real_escape_string($userid);
		if(preg_match('/,/', $userid)) {
			$mode = 'name';
			$force = 0;
		}
		else
			$mode = 'userid';
		if(! checkUserHasPerm('User Lookup (global)') &&
		   $user['affiliationid'] != $affilid) {
			print "<font color=red>$userid not found</font><br>\n";
			return;
		}
		if($mode == 'userid') {
			$query = "SELECT id "
			       . "FROM user "
			       . "WHERE unityid = '$esc_userid' AND "
			       .       "affiliationid = $affilid";
			$affilname = getAffiliationName($affilid);
			$userid = "$userid@$affilname";
			$esc_userid = "$esc_userid@$affilname";
		}
		else {
			$tmp = explode(',', $userid);
			$last = mysql_real_escape_string(trim($tmp[0]));
			$first = mysql_real_escape_string(trim($tmp[1]));
			$query = "SELECT CONCAT(u.unityid, '@', a.name) AS unityid "
			       . "FROM user u, "
			       .      "affiliation a "
			       . "WHERE u.firstname = '$first' AND "
			       .       "u.lastname = '$last' AND "
			       .       "u.affiliationid = $affilid AND "
			       .       "a.id = $affilid";
		}
		$qh = doQuery($query, 101);
		if(! mysql_num_rows($qh)) {
			if($mode == 'name') {
				print "<font color=red>User not found</font><br>\n";
				return;
			}
			else
				print "<font color=red>$userid not currently found in VCL user database, will try to add...</font><br>\n";
		}
		elseif($force) {
			$_SESSION['userresources'] = array();
			$row = mysql_fetch_assoc($qh);
			$newtime = unixToDatetime(time() - SECINDAY - 5);
			$query = "UPDATE user SET lastupdated = '$newtime' WHERE id = {$row['id']}";
			doQuery($query, 101);
		}
		elseif($mode == 'name') {
			$row = mysql_fetch_assoc($qh);
			$userid = $row['unityid'];
			$esc_userid = $row['unityid'];
		}

		$userdata = getUserInfo($esc_userid);
		if(is_null($userdata)) {
			$userdata = getUserInfo($esc_userid, 1);
			if(is_null($userdata)) {
				print "<font color=red>$userid not found in any known systems</font><br>\n";
				return;
			}
		}
		$userdata["groups"] = getUsersGroups($userdata["id"], 1, 1);
		print "<TABLE>\n";
		if(! empty($userdata['unityid'])) {
			print "  <TR>\n";
			print "    <TH align=right>User ID:</TH>\n";
			print "    <TD>{$userdata["unityid"]}</TD>\n";
			print "  </TR>\n";
		}
		if(! empty($userdata['firstname'])) {
			print "  <TR>\n";
			print "    <TH align=right>First Name:</TH>\n";
			print "    <TD>{$userdata["firstname"]}</TD>\n";
			print "  </TR>\n";
		}
		if(! empty($userdata['lastname'])) {
			print "  <TR>\n";
			print "    <TH align=right>Last Name:</TH>\n";
			print "    <TD>{$userdata["lastname"]}</TD>\n";
			print "  </TR>\n";
		}
		if(! empty($userdata['preferredname'])) {
			print "  <TR>\n";
			print "    <TH align=right>Preferred Name:</TH>\n";
			print "    <TD>{$userdata["preferredname"]}</TD>\n";
			print "  </TR>\n";
		}
		if(! empty($userdata['affiliation'])) {
			print "  <TR>\n";
			print "    <TH align=right>Affiliation:</TH>\n";
			print "    <TD>{$userdata["affiliation"]}</TD>\n";
			print "  </TR>\n";
		}
		if(! empty($userdata['email'])) {
			print "  <TR>\n";
			print "    <TH align=right>Email:</TH>\n";
			print "    <TD>{$userdata["email"]}</TD>\n";
			print "  </TR>\n";
		}
		print "  <TR>\n";
		print "    <TH align=right style=\"vertical-align: top\">Groups:</TH>\n";
		print "    <TD>\n";
		uasort($userdata["groups"], "sortKeepIndex");
		foreach($userdata["groups"] as $group) {
			print "      $group<br>\n";
		}
		print "    </TD>\n";
		print "  </TR>\n";
		print "  <TR>\n";
		print "    <TH align=right style=\"vertical-align: top\">User Group Permissions:</TH>\n";
		print "    <TD>\n";
		if(count($userdata['groupperms'])) {
			foreach($userdata['groupperms'] as $perm)
				print "      $perm<br>\n";
		}
		else
			print "      No additional user group permissions\n";
		print "    </TD>\n";
		print "  </TR>\n";
		print "  <TR>\n";
		print "    <TH align=right style=\"vertical-align: top\">Privileges (found somewhere in the tree):</TH>\n";
		print "    <TD>\n";
		uasort($userdata["privileges"], "sortKeepIndex");
		foreach($userdata["privileges"] as $priv) {
			if($priv == "block" || $priv == "cascade")
				continue;
			print "      $priv<br>\n";
		}
		print "    </TD>\n";
		print "  </TR>\n";
		print "</TABLE>\n";

		# get user's resources
		$userResources = getUserResources(array("imageCheckOut"), array("available"), 0, 0, $userdata['id']);

		# find nodes where user has privileges
		$query = "SELECT p.name AS privnode, "
		       .        "upt.name AS userprivtype, "
		       .        "up.privnodeid "
		       . "FROM userpriv up, "
		       .      "privnode p, "
		       .      "userprivtype upt "
		       . "WHERE up.privnodeid = p.id AND "
		       .       "up.userprivtypeid = upt.id AND "
		       .       "up.userid = {$userdata['id']} "
		       . "ORDER BY p.name, "
		       .          "upt.name";
		$qh = doQuery($query, 101);
		if(mysql_num_rows($qh)) {
			print "Nodes where user is granted privileges:<br>\n";
			print "<TABLE>\n";
			$privnodeid = 0;
			while($row = mysql_fetch_assoc($qh)) {
				if($privnodeid != $row['privnodeid']) {
					if($privnodeid) {
						print "    </TD>\n";
						print "  </TR>\n";
					}
					print "  <TR>\n";
					$privnodeid = $row['privnodeid'];
					print "    <TH align=right>{$row['privnode']}</TH>\n";
					print "    <TD>\n";
				}
				print "      {$row['userprivtype']}<br>\n";
			}
			print "    </TD>\n";
			print "  </TR>\n";
			print "</TABLE>\n";
		}

		# find nodes where user's groups have privileges
		if(! empty($userdata['groups'])) {
			$query = "SELECT DISTINCT p.name AS privnode, "
			       .        "upt.name AS userprivtype, "
			       .        "up.privnodeid "
			       . "FROM userpriv up, "
			       .      "privnode p, "
			       .      "userprivtype upt "
			       . "WHERE up.privnodeid = p.id AND "
			       .       "up.userprivtypeid = upt.id AND "
			       .       "upt.name != 'cascade' AND "
			       .       "upt.name != 'block' AND "
			       .       "up.usergroupid IN (" . implode(',', array_keys($userdata['groups'])) . ") "
			       . "ORDER BY p.name, "
			       .          "upt.name";
			$qh = doQuery($query, 101);
			if(mysql_num_rows($qh)) {
				print "Nodes where user's groups are granted privileges:<br>\n";
				print "<TABLE>\n";
				$privnodeid = 0;
				while($row = mysql_fetch_assoc($qh)) {
					if($privnodeid != $row['privnodeid']) {
						if($privnodeid) {
							print "    </TD>\n";
							print "  </TR>\n";
						}
						print "  <TR>\n";
						$privnodeid = $row['privnodeid'];
						print "    <TH align=right>{$row['privnode']}</TH>\n";
						print "    <TD>\n";
					}
					print "      {$row['userprivtype']}<br>\n";
				}
				print "    </TD>\n";
				print "  </TR>\n";
				print "</TABLE>\n";
			}
		}
		print "<table>\n";
		print "  <tr>\n";
		print "    <th>Images User Has Access To:<th>\n";
		print "    <td>\n";
		foreach($userResources['image'] as $img)
			print "      $img<br>\n";
		print "    </td>\n";
		print "  </tr>\n";
		print "</table>\n";

		# login history
		$query = "SELECT authmech, "
		       .        "timestamp, "
		       .        "passfail, "
		       .        "remoteIP, "
		       .        "code "
		       . "FROM loginlog "
		       . "WHERE user = '{$userdata['unityid']}' AND "
		       .       "affiliationid = {$userdata['affiliationid']} "
		       . "ORDER BY timestamp DESC "
		       . "LIMIT 8";
		$logins = array();
		$qh = doQuery($query);
		while($row = mysql_fetch_assoc($qh))
			$logins[] = $row;
		if(count($logins)) {
			$logins = array_reverse($logins);
			print "<h3>Login History (last 8 attempts)</h3>\n";
			print "<table summary=\"login attempts\">\n";
			print "<colgroup>\n";
			print "<col class=\"logincol\" />\n";
			print "<col class=\"logincol\" />\n";
			print "<col class=\"logincol\" />\n";
			print "<col class=\"logincol\" />\n";
			print "<col />\n";
			print "</colgroup>\n";
			print "  <tr>\n";
			print "    <th>Authentication Method</th>\n";
			print "    <th>Timestamp</th>\n";
			print "    <th>Result</th>\n";
			print "    <th>Remote IP</th>\n";
			print "    <th>Extra Info</th>\n";
			print "  </tr>\n";
			foreach($logins as $login) {
				print "  <tr>\n";
				print "    <td class=\"logincell\">{$login['authmech']}</td>\n";
				$ts = prettyDatetime($login['timestamp'], 1);
				print "    <td class=\"logincell\">$ts</td>\n";
				if($login['passfail'])
					print "    <td class=\"logincell\"><font color=\"#008000\">Pass</font></td>\n";
				else
					print "    <td class=\"logincell\"><font color=\"red\">Fail</font></td>\n";
				print "    <td class=\"logincell\">{$login['remoteIP']}</td>\n";
				print "    <td class=\"logincell\">{$login['code']}</td>\n";
				print "  </tr>\n";
			}
			print "</table>\n";
		}
		else {
			print "<h3>Login History</h3>\n";
			print "There are no login attempts by this user.<br>\n";
		}


		# reservation history
		$requests = array();
		$query = "SELECT DATE_FORMAT(l.start, '%W, %b %D, %Y, %h:%i %p') AS start, "
		       .        "DATE_FORMAT(l.finalend, '%W, %b %D, %Y, %h:%i %p') AS end, "
		       .        "c.hostname, "
		       .        "i.prettyname AS prettyimage, "
		       .        "l.ending "
		       . "FROM log l, "
		       .      "image i, "
		       .      "computer c, "
		       .      "sublog s "
		       . "WHERE l.userid = {$userdata['id']} AND "
		       .        "s.logid = l.id AND "
		       .        "i.id = s.imageid AND "
		       .        "c.id = s.computerid "
		       . "ORDER BY l.start DESC "
		       . "LIMIT 5";
		$qh = doQuery($query, 290);
		while($row = mysql_fetch_assoc($qh))
			array_push($requests, $row);
		$requests = array_reverse($requests);
		if(! empty($requests)) {
			print "<h3>User's last " . count($requests) . " reservations:</h3>\n";
			print "<table>\n";
			$first = 1;
			foreach($requests as $req) {
				if($first)
					$first = 0;
				else {
					print "  <tr>\n";
					print "    <td colspan=2><hr></td>\n";
					print "  </tr>\n";
				}
				print "  <tr>\n";
				print "    <th align=right>Image:</th>\n";
				print "    <td>{$req['prettyimage']}</td>\n";
				print "  </tr>\n";
				print "  <tr>\n";
				print "    <th align=right>Computer:</th>\n";
				print "    <td>{$req['hostname']}</td>\n";
				print "  </tr>\n";
				print "  <tr>\n";
				print "    <th align=right>Start:</th>\n";
				print "    <td>{$req['start']}</td>\n";
				print "  </tr>\n";
				print "  <tr>\n";
				print "    <th align=right>End:</th>\n";
				print "    <td>{$req['end']}</td>\n";
				print "  </tr>\n";
				print "  <tr>\n";
				print "    <th align=right>Ending:</th>\n";
				print "    <td>{$req['ending']}</td>\n";
				print "  </tr>\n";
			}
			print "</table>\n";
		}
		else
			print "User made no reservations in the past week.<br>\n";
	}
	print "</div>\n";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn recurseGetChildren($node)
///
/// \param $node - a node id
///
/// \return an array of nodes that are children of $node
///
/// \brief foreach child node of $node, adds it to an array and calls
/// self to add that child's children
///
////////////////////////////////////////////////////////////////////////////////
function recurseGetChildren($node) {
	$children = array();
	$qh = doQuery("SELECT id FROM privnode WHERE parent = $node", 340);
	while($row = mysql_fetch_row($qh)) {
		array_push($children, $row[0]);
		$children = array_merge($children, recurseGetChildren($row[0]));
	}
	return $children;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn printUserPrivRow($privname, $rownum, $privs, $types,
///                               $cascadeprivs, $usergroup, $disabled)
///
/// \param $privname - privilege name
/// \param $rownum - number of the privilege row on this page
/// \param $privs - an array of user's privileges
/// \param $types - an array of privilege types
/// \param $cascadeprivs - an array of user's cascaded privileges
/// \param $usergroup - 'user' if this is a user row, or 'group' if this is a
/// group row
/// \param $disabled - 0 or 1; whether or not the checkboxes should be disabled
///
/// \brief prints a table row for this $privname
///
////////////////////////////////////////////////////////////////////////////////
function printUserPrivRow($privname, $rownum, $privs, $types, 
                          $cascadeprivs, $usergroup, $disabled) {
	$allprivs = $cascadeprivs + $privs;
	print "  <TR>\n";
	if($usergroup == 'group') {
		$id = $allprivs[$privname]['id'];
		print "    <TH><span id=\"usergrp$id\" onmouseover=getGroupMembers(";
		print "\"$id\",\"usergrp$id\",\"ugmcont\"); onmouseout=";
		print "getGroupMembersCancel(\"usergrp$id\");>$privname";
		if($usergroup == 'group' && ! empty($allprivs[$privname]['affiliation']))
			print "@{$allprivs[$privname]['affiliation']}";
		print "</span></TH>\n";
	}
	else
		print "<TH>$privname</TH>\n";

	if($disabled)
		$disabled = 'disabled=disabled';
	else
		$disabled = '';

	# block rights
	if(array_key_exists($privname, $privs) && 
	   (($usergroup == 'user' &&
	   in_array("block", $privs[$privname])) ||
	   ($usergroup == 'group' &&
	   in_array("block", $privs[$privname]['privs'])))) {
		$checked = "checked";
		$blocked = 1;
	}
	else {
		$checked = "";
		$blocked = 0;
	}
	$count = count($types) + 1;
	if($usergroup == 'user') {
		$usergroup = 1;
		$name = "privrow[$privname:block]";
	}
	elseif($usergroup == 'group') {
		$usergroup = 2;
		$name = "privrow[{$allprivs[$privname]['id']}:block]";
	}
	print "    <TD align=center bgcolor=gray>\n";
	print "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=ck$rownum:block ";
	print "name=\"$name\" onClick=\"changeCascadedRights(this.checked, $rownum, ";
	print "$count, 1, $usergroup);\" $checked $disabled></TD>\n";

	#cascade rights
	if(array_key_exists($privname, $privs) && 
	   (($usergroup == 1 &&
	   in_array("cascade", $privs[$privname])) ||
		($usergroup == 2 &&
	   in_array("cascade", $privs[$privname]['privs']))))
		$checked = "checked";
	else
		$checked = "";
	if($usergroup == 1)
		$name = "privrow[$privname:cascade]";
	else
		$name = "privrow[{$allprivs[$privname]['id']}:cascade]";
	print "    <TD align=center bgcolor=\"#008000\" id=cell$rownum:0>";
	print "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=ck$rownum:0 ";
	print "name=\"$name\" onClick=\"privChange(this.checked, $rownum, 0, ";
	print "$usergroup);\" $checked $disabled></TD>\n";

	# normal rights
	$j = 1;
	foreach($types as $type) {
		$bgcolor = "";
		$checked = "";
		$value = "";
		$cascaded = 0;
		if(array_key_exists($privname, $cascadeprivs) && 
		   (($usergroup == 1 &&
		   in_array($type, $cascadeprivs[$privname])) ||
		   ($usergroup == 2 &&
		   in_array($type, $cascadeprivs[$privname]['privs'])))) {
			$bgcolor = "bgcolor=\"#008000\"";
			$checked = "checked";
			$value = "value=cascade";
			$cascaded = 1;
		}
		if(array_key_exists($privname, $privs) && 
		   (($usergroup == 1 &&
		   in_array($type, $privs[$privname])) ||
		   ($usergroup == 2 &&
		   in_array($type, $privs[$privname]['privs'])))) {
			if($cascaded) {
				$value = "value=cascadesingle";
			}
			else {
				$checked = "checked";
				$value = "value=single";
			}
		}
		if($usergroup == 1)
			$name = "privrow[$privname:$type]";
		else
			$name = "privrow[{$allprivs[$privname]['id']}:$type]";
		print "    <TD align=center id=cell$rownum:$j $bgcolor><INPUT ";
		print "type=checkbox dojoType=dijit.form.CheckBox name=\"$name\" ";
		print "id=ck$rownum:$j $checked $value $disabled ";
		print "onClick=\"nodeCheck(this.checked, $rownum, $j, $usergroup)\">";
		#print "onBlur=\"nodeCheck(this.checked, $rownum, $j, $usergroup)\">";
		print "</TD>\n";
		$j++;
	}
	print "  </TR>\n";
	$count = count($types) + 1;
	if($blocked) {
		print "<script language=\"Javascript\">\n";
		print "dojo.addOnLoad(function() {setTimeout(\"changeCascadedRights(true, $rownum, $count, 0, 0)\", 500)});\n";
		print "</script>\n";
	}
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn getUserPrivRowHTML($privname, $rownum, $privs, $types,
///                                 $cascadeprivs, $usergroup, $disabled)
///
/// \param $privname - privilege name
/// \param $rownum - number of the privilege row on this page
/// \param $privs - an array of user's privileges
/// \param $types - an array of privilege types
/// \param $cascadeprivs - an array of user's cascaded privileges
/// \param $usergroup - 'user' if this is a user row, or 'group' if this is a
/// group row
/// \param $disabled - 0 or 1; whether or not the checkboxes should be disabled
///
/// \return a string of HTML code for a user privilege row
///
/// \brief creates HTML for a user privilege row and returns it 
///
////////////////////////////////////////////////////////////////////////////////
function getUserPrivRowHTML($privname, $rownum, $privs, $types, 
                            $cascadeprivs, $usergroup, $disabled) {
	$allprivs = $cascadeprivs + $privs;
	$text = "";
	$js = "";
	$text .= "<TR>";
	if($usergroup == 'group') {
		$id = $allprivs[$privname]['id'];
		$text .= "<TH><span id=\"usergrp$id\" onmouseover=getGroupMembers(";
		$text .= "\"$id\",\"usergrp$id\",\"ugmcont\"); onmouseout=";
		$text .= "getGroupMembersCancel(\"usergrp$id\");>$privname";
		if($usergroup == 'group' && ! empty($allprivs[$privname]['affiliation']))
			$text .= "@{$allprivs[$privname]['affiliation']}";
		$text .= "</span></TH>";
	}
	else
		$text .= "<TH>$privname</TH>";

	if($disabled)
		$disabled = 'disabled=disabled';
	else
		$disabled = '';

	# block rights
	if(array_key_exists($privname, $privs) && 
	   (($usergroup == 'user' &&
	   in_array("block", $privs[$privname])) ||
	   ($usergroup == 'group' &&
	   in_array("block", $privs[$privname]['privs'])))) {
		$checked = "checked";
		$blocked = 1;
	}
	else {
		$checked = "";
		$blocked = 0;
	}
	$count = count($types) + 1;
	if($usergroup == 'user') {
		$usergroup = 1;
		$name = "privrow[$privname:block]";
	}
	elseif($usergroup == 'group') {
		$usergroup = 2;
		$name = "privrow[{$allprivs[$privname]['id']}:block]";
	}
	$text .= "    <TD align=center bgcolor=gray><INPUT type=checkbox ";
	$text .= "dojoType=dijit.form.CheckBox id=ck$rownum:block name=\"$name\" ";
	$text .= "$checked $disabled onClick=\"changeCascadedRights";
	$text .= "(this.checked, $rownum, $count, 1, $usergroup)\"></TD>";

	#cascade rights
	if(array_key_exists($privname, $privs) && 
	   (($usergroup == 1 &&
	   in_array("cascade", $privs[$privname])) ||
		($usergroup == 2 &&
	   in_array("cascade", $privs[$privname]['privs']))))
		$checked = "checked";
	else
		$checked = "";
	if($usergroup == 1)
		$name = "privrow[$privname:cascade]";
	else
		$name = "privrow[{$allprivs[$privname]['id']}:cascade]";
	$text .= "    <TD align=center bgcolor=\"#008000\" id=cell$rownum:0>";
	$text .= "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=ck$rownum:0 ";
	$text .= "name=\"$name\" onClick=\"privChange(this.checked, $rownum, 0, ";
	$text .= "$usergroup);\" $checked $disabled></TD>";

	# normal rights
	$j = 1;
	foreach($types as $type) {
		$bgcolor = "";
		$checked = "";
		$value = "";
		$cascaded = 0;
		if(array_key_exists($privname, $cascadeprivs) && 
		   (($usergroup == 1 &&
		   in_array($type, $cascadeprivs[$privname])) ||
		   ($usergroup == 2 &&
		   in_array($type, $cascadeprivs[$privname]['privs'])))) {
			$bgcolor = "bgcolor=\"#008000\"";
			$checked = "checked";
			$value = "value=cascade";
			$cascaded = 1;
		}
		if(array_key_exists($privname, $privs) && 
		   (($usergroup == 1 &&
		   in_array($type, $privs[$privname])) ||
		   ($usergroup == 2 &&
		   in_array($type, $privs[$privname]['privs'])))) {
			if($cascaded) {
				$value = "value=cascadesingle";
			}
			else {
				$checked = "checked";
				$value = "value=single";
			}
		}
		if($usergroup == 1)
			$name = "privrow[$privname:$type]";
		else
			$name = "privrow[{$allprivs[$privname]['id']}:$type]";
		$text .= "    <TD align=center id=cell$rownum:$j $bgcolor><INPUT ";
		$text .= "type=checkbox dojoType=dijit.form.CheckBox name=\"$name\" ";
		$text .= "id=ck$rownum:$j $checked $value $disabled ";
		$text .= "onClick=\"nodeCheck(this.checked, $rownum, $j, $usergroup)\">";
		#$text .= "onBlur=\"nodeCheck(this.checked, $rownum, $j, $usergroup)\">";
		$text .= "</TD>";
		$j++;
	}
	$text .= "  </TR>";
	$count = count($types) + 1;
	if($blocked) {
		$js .= "changeCascadedRights(true, $rownum, $count, 0, 0);";
	}
	return array('html' => $text,
	             'javascript' => $js);
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn jsonGetUserGroupMembers()
///
/// \brief accepts a user group id and dom id and prints a json array with 2
/// elements: members - a <br> separated string of user group members, and
/// domid - the passed in domid
///
////////////////////////////////////////////////////////////////////////////////
function jsonGetUserGroupMembers() {
	global $user;
	$usergrpid = processInputVar('groupid', ARG_NUMERIC);
	$domid = processInputVar('domid', ARG_STRING);
	$query = "SELECT g.ownerid, "
	       .        "g2.name AS editgroup, "
	       .        "g2.editusergroupid AS editgroupid "
	       . "FROM usergroup g "
	       . "LEFT JOIN usergroup g2 ON (g.editusergroupid = g2.id) "
	       . "WHERE g.id = $usergrpid";
	$qh = doQuery($query, 101);
	if(! ($grpdata = mysql_fetch_assoc($qh))) {
		# problem getting group members
		$msg = 'failed to fetch group members';
		$arr = array('members' => $msg, 'domid' => $domid);
		sendJSON($arr);
		return;
	}
	if($grpdata["ownerid"] != $user["id"] && ! (array_key_exists($grpdata["editgroupid"], $user["groups"]))) {
		# user doesn't have access to view membership
		$msg = '(not authorized to view membership)';
		$arr = array('members' => $msg, 'domid' => $domid);
		sendJSON($arr);
		return;
	}

	$grpmembers = getUserGroupMembers($usergrpid);
	$members = '';
	foreach($grpmembers as $group)
		$members .= "$group<br>";
	if($members == '')
		$members = '(empty group)';
	$arr = array('members' => $members, 'domid' => $domid);
	sendJSON($arr);
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn getResourcePrivRowHTML($privname, $rownum, $privs, $types,
///                                     $resourcegroups, $resgroupmembers,
///                                     $cascadeprivs, $disabled)
///
/// \param $privname - privilege name
/// \param $rownum - number of the privilege row on this page
/// \param $privs - an array of user's privileges
/// \param $types - an array of privilege types
/// \param $resourcegroups - array from getResourceGroups()
/// \param $resgroupmembers - array from getResourceGroupMembers()
/// \param $cascadeprivs - an array of user's cascaded privileges
/// \param $disabled - 0 or 1; whether or not the checkboxes should be disabled
///
/// \return a string of HTML code for a resource row
///
/// \brief creates HTML for a resource privilege row and returns it 
///
////////////////////////////////////////////////////////////////////////////////
function getResourcePrivRowHTML($privname, $rownum, $privs, $types,
                                $resourcegroups, $resgroupmembers,
                                $cascadeprivs, $disabled) {
	global $user;
	$text = "";
	$js = "";
	$text .= "  <TR>\n";
	list($grptype, $name, $id) = explode('/', $privname);
	$text .= "    <TH>\n";
	$text .= "      <span id=\"resgrp$id\" onmouseover=getGroupMembers(\"$id\",";
	$text .= "\"resgrp$id\",\"rgmcont\"); onmouseout=getGroupMembersCancel";
	$text .= "(\"resgrp$id\");>$name</span>\n";
	$text .= "    </TH>\n";
	$text .= "    <TH>$grptype</TH>\n";

	if($disabled)
		$disabled = 'disabled=disabled';
	else
		$disabled = '';

	# block rights
	if(array_key_exists($privname, $privs) && 
	   in_array("block", $privs[$privname])) {
		$checked = "checked";
		$blocked = 1;
	}
	else {
		$checked = "";
		$blocked = 0;
	}
	$count = count($types) + 1;
	$name = "privrow[" . $privname . ":block]";
	$text .= "    <TD align=center bgcolor=gray><INPUT type=checkbox ";
	$text .= "dojoType=dijit.form.CheckBox id=ck$rownum:block name=\"$name\" ";
	$text .= "$checked $disabled onClick=\"changeCascadedRights";
	$text .= "(this.checked, $rownum, $count, 1, 3)\"></TD>\n";

	#cascade rights
	if(array_key_exists($privname, $privs) && 
	   in_array("cascade", $privs[$privname]))
		$checked = "checked";
	else
		$checked = "";
	$name = "privrow[" . $privname . ":cascade]";
	$text .= "    <TD align=center bgcolor=\"#008000\" id=cell$rownum:0>";
	$text .= "<INPUT type=checkbox dojoType=dijit.form.CheckBox id=ck$rownum:0 ";
	$text .= "onClick=\"privChange(this.checked, $rownum, 0, 3);\" ";
	$text .= "name=\"$name\" $checked $disabled></TD>\n";

	# normal rights
	$j = 1;
	foreach($types as $type) {
		if($type == 'block' || $type == 'cascade')
			continue;
		$bgcolor = "";
		$checked = "";
		$value = "";
		$cascaded = 0;
		if(array_key_exists($privname, $cascadeprivs) && 
		   in_array($type, $cascadeprivs[$privname])) {
			$bgcolor = "bgcolor=\"#008000\"";
			$checked = "checked";
			$value = "value=cascade";
			$cascaded = 1;
		}
		if(array_key_exists($privname, $privs) && 
		       in_array($type, $privs[$privname])) {
			if($cascaded) {
				$value = "value=cascadesingle";
			}
			else {
				$checked = "checked";
				$value = "value=single";
			}
		}
		// if $type is administer, manageGroup, or manageMapping, and it is not
		# checked, and the user is not in the resource owner group, don't print
		# the checkbox
		if(($type == "administer" || $type == "manageGroup" || $type == "manageMapping") &&
		   $checked != "checked" && 
		   ! array_key_exists($resourcegroups[$id]["ownerid"], $user["groups"])) {
			$text .= "<TD><img src=images/blank.gif></TD>\n";
		}
		// if group type is schedule, don't print available or manageMapping checkboxes
		// if group type is managementnode, don't print available checkbox
		// if group type is serverprofile, don't print manageMapping checkbox
		elseif(($grptype == 'schedule' && ($type == 'available' || $type == 'manageMapping')) ||
		      ($grptype == 'managementnode' && $type == 'available') ||
		      ($grptype == 'serverprofile' && $type == 'manageMapping')) {
			$text .= "<TD><img src=images/blank.gif></TD>\n";
		}
		else {
			$name = "privrow[" . $privname . ":" . $type . "]";
			$text .= "    <TD align=center id=cell$rownum:$j $bgcolor><INPUT ";
			$text .= "type=checkbox dojoType=dijit.form.CheckBox name=\"$name\" ";
			$text .= "id=ck$rownum:$j $checked $value $disabled ";
			$text .= "onClick=\"nodeCheck(this.checked, $rownum, $j, 3)\">";
			$text .= "</TD>\n";
		}
		$j++;
	}
	$text .= "  </TR>\n";
	$count = count($types) + 1;
	if($blocked) {
		$js .= "changeCascadedRights(true, $rownum, $count, 0, 0);";
	}
	return array('html' => $text,
	             'javascript' => $js);
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn jsonGetResourceGroupMembers()
///
/// \brief accepts a resource group id and dom id and prints a json array with 2
/// elements: members - a <br> separated string of resource group members, and
/// domid - the passed in domid
///
////////////////////////////////////////////////////////////////////////////////
function jsonGetResourceGroupMembers() {
	$resgrpid = processInputVar('groupid', ARG_NUMERIC);
	$domid = processInputVar('domid', ARG_STRING);
	$query = "SELECT rt.name "
	       . "FROM resourcegroup rg, "
	       .      "resourcetype rt "
	       . "WHERE rg.id = $resgrpid AND "
	       .       "rg.resourcetypeid = rt.id";
	$qh = doQuery($query, 101);
	if($row = mysql_fetch_assoc($qh)) {
		$type = $row['name'];
		if($type == 'computer' || $type == 'managementnode')
			$field = 'hostname';
		elseif($type == 'image')
			$field = 'prettyname';
		elseif($type == 'schedule')
			$field = 'name';
		elseif($type == 'serverprofile')
			$field = 'name';
		$query = "SELECT t.$field AS item "
		       . "FROM $type t, "
		       .      "resource r, "
		       .      "resourcegroupmembers rgm "
		       . "WHERE rgm.resourcegroupid = $resgrpid AND "
		       .       "rgm.resourceid = r.id AND "
		       .       "r.subid = t.id";
		$qh = doQuery($query, 101);
		$members = '';
		while($row = mysql_fetch_assoc($qh))
			$members .= "{$row['item']}<br>";
		if($members == '')
			$members = '(empty group)';
		$arr = array('members' => $members, 'domid' => $domid);
		sendJSON($arr);
	}
	else {
		$members = '(failed to lookup members)';
		$arr = array('members' => $members, 'domid' => $domid);
		sendJSON($arr);
	}
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn getNodePrivileges($node, $type, $privs)
///
/// \param $node - id of node
/// \param $type - (optional) resources, users, usergroups, or all
/// \param $privs - (optional) privilege array as returned by this function or
/// getNodeCascadePrivileges
///
/// \return an array of privileges at the node:\n
///\pre
///Array\n
///(\n
///    [resources] => Array\n
///        (\n
///        )\n
///    [users] => Array\n
///        (\n
///            [userid0] => Array\n
///                (\n
///                    [0] => priv0\n
///                        ...\n
///                    [N] => privN\n
///                )\n
///                ...\n
///            [useridN] => Array()\n
///        )\n
///    [usergroups] => Array\n
///        (\n
///            [group0] => Array\n
///                (\n
///                    [0] => priv0\n
///                        ...\n
///                    [N] => privN\n
///                )\n
///                ...\n
///            [groupN] => Array()\n
///        )\n
///)
///
/// \brief gets the requested privileges at the specified node
///
////////////////////////////////////////////////////////////////////////////////
function getNodePrivileges($node, $type="all", $privs=0) {
	global $user;
	$key = getKey(array($node, $type, $privs));
	if(array_key_exists($key, $_SESSION['nodeprivileges']))
		return $_SESSION['nodeprivileges'][$key];
	if(! $privs)
		$privs = array("resources" => array(),
		               "users" => array(),
		               "usergroups" => array());
	static $resourcedata = array();
	if(empty($resourcedata)) {
		$query = "SELECT g.id AS id, "
		       .        "p.type AS privtype, "
		       .        "g.name AS name, "
		       .        "t.name AS type, "
		       .        "p.privnodeid "
		       . "FROM resourcepriv p, "
		       .      "resourcetype t, "
		       .      "resourcegroup g "
		       . "WHERE p.resourcegroupid = g.id AND "
		       .       "g.resourcetypeid = t.id "
		       . "ORDER BY p.privnodeid";
		$qh = doQuery($query, 350);
		while($row = mysql_fetch_assoc($qh)) {
			if(! array_key_exists($row['privnodeid'], $resourcedata))
				$resourcedata[$row['privnodeid']] = array();
			$resourcedata[$row['privnodeid']][] = $row;
		}
	}
	if($type == "resources" || $type == "all") {
		if(array_key_exists($node, $resourcedata)) {
			foreach($resourcedata[$node] as $data) {
				$name = "{$data["type"]}/{$data["name"]}/{$data["id"]}";
				if(! array_key_exists($name, $privs["resources"]))
					$privs["resources"][$name] = array();
				$privs["resources"][$name][] = $data["privtype"];
			}
		}
	}
	if($type == "users" || $type == "all") {
		$query = "SELECT t.name AS name, "
		       .        "CONCAT(u.unityid, '@', a.name) AS unityid "
		       . "FROM user u, "
		       .      "userpriv up, "
		       .      "userprivtype t, "
		       .      "affiliation a "
		       . "WHERE up.privnodeid = $node AND "
		       .       "up.userprivtypeid = t.id AND "
		       .       "up.userid = u.id AND "
		       .       "up.userid IS NOT NULL AND "
		       .       "u.affiliationid = a.id "
		       . "ORDER BY u.unityid";
		$qh = doQuery($query, 351);
		while($row = mysql_fetch_assoc($qh)) {
			if(array_key_exists($row["unityid"], $privs["users"])) {
				array_push($privs["users"][$row["unityid"]], $row["name"]);
			}
			else {
				$privs["users"][$row["unityid"]] = array($row["name"]);
			}
		}
	}
	if($type == "usergroups" || $type == "all") {
		$query = "SELECT t.name AS priv, "
		       .        "g.name AS groupname, "
		       .        "g.affiliationid, "
		       .        "a.name AS affiliation, "
		       .        "g.id "
		       . "FROM userpriv up, "
		       .      "userprivtype t, "
		       .      "usergroup g "
		       . "LEFT JOIN affiliation a ON (g.affiliationid = a.id) "
		       . "WHERE up.privnodeid = $node AND "
		       .       "up.userprivtypeid = t.id AND "
		       .       "up.usergroupid = g.id AND "
		       .       "up.usergroupid IS NOT NULL "
		       . "ORDER BY g.name";
		$qh = doQuery($query, 352);
		while($row = mysql_fetch_assoc($qh)) {
			if(array_key_exists($row["groupname"], $privs["usergroups"]))
				array_push($privs["usergroups"][$row["groupname"]]['privs'], $row["priv"]);
			else
				$privs["usergroups"][$row["groupname"]] = array('id' => $row['id'],
				                                                'affiliationid' => $row['affiliationid'],
				                                                'affiliation' => $row['affiliation'],
				                                                'privs' => array($row['priv']));
		}
	}
	$_SESSION['nodeprivileges'][$key] = $privs;
	return $privs;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn getNodeCascadePrivileges($node, $type="all", $privs=0)
///
/// \param $node - id of node
/// \param $type - (optional) resources, users, usergroups, or all
/// \param $privs - (optional) privilege array as returned by this function or
/// getNodeCascadePrivileges
///
/// \return an array of privileges cascaded to the node:\n
///Array\n
///(\n
///    [resources] => Array\n
///        (\n
///        )\n
///    [users] => Array\n
///        (\n
///            [userid0] => Array\n
///                (\n
///                    [0] => priv0\n
///                        ...\n
///                    [N] => privN\n
///                )\n
///                ...\n
///            [useridN] => Array()\n
///        )\n
///    [usergroups] => Array\n
///        (\n
///            [group0] => Array\n
///                (\n
///                    [0] => priv0\n
///                        ...\n
///                    [N] => privN\n
///                )\n
///                ...\n
///            [groupN] => Array()\n
///        )\n
///)
///
/// \brief gets the requested cascaded privileges for the specified node
///
////////////////////////////////////////////////////////////////////////////////
function getNodeCascadePrivileges($node, $type="all", $privs=0) {
	$key = getKey(array($node, $type, $privs));
	if(array_key_exists($key, $_SESSION['cascadenodeprivileges']))
		return $_SESSION['cascadenodeprivileges'][$key];
	if(! $privs)
		$privs = array("resources" => array(),
		               "users" => array(),
		               "usergroups" => array());

	# get node's parents
	$nodelist = getParentNodes($node);

	# get all block data
	static $allblockdata = array();
	if(empty($allblockdata)) {
		$query = "SELECT g.name AS name, "
		       .        "t.name AS type, "
		       .        "p.privnodeid "
		       . "FROM resourcepriv p, "
		       .      "resourcetype t, "
		       .      "resourcegroup g "
		       . "WHERE p.resourcegroupid = g.id AND "
		       .       "g.resourcetypeid = t.id AND "
		       .       "p.type = 'block'";
		$qh = doQuery($query);
		while($row = mysql_fetch_assoc($qh))
			if(! array_key_exists($row['privnodeid'], $allblockdata))
				$allblockdata[$row['privnodeid']] = array();
			$allblockdata[$row['privnodeid']][] = "{$row["type"]}/{$row["name"]}";
	}

	# get resource group block data
	$inlist = implode(',', $nodelist);
	$blockdata = array();
	foreach($nodelist as $nodeid) {
		if(array_key_exists($nodeid, $allblockdata))
			$blockdata[$nodeid] = $allblockdata[$nodeid];
	}

	# get all cascade data
	static $allcascadedata = array();
	if(empty($allcascadedata)) {
		$query = "SELECT g.id AS id, "
		       .        "p.type AS privtype, "
		       .        "g.name AS name, "
		       .        "t.name AS type, "
		       .        "p.privnodeid "
		       . "FROM resourcepriv p, "
		       .      "resourcetype t, "
		       .      "resourcegroup g, "
		       .      "resourcepriv p2 "
		       . "WHERE p.resourcegroupid = g.id AND "
		       .       "g.resourcetypeid = t.id AND "
		       .       "p.type != 'block' AND "
		       .       "p.type != 'cascade' AND "
		       .       "p.resourcegroupid = p2.resourcegroupid AND "
		       .       "p.privnodeid = p2.privnodeid AND "
		       .       "p2.type = 'cascade'";
		$qh = doQuery($query);
		while($row = mysql_fetch_assoc($qh)) {
			if(! array_key_exists($row['privnodeid'], $allcascadedata))
				$allcascadedata[$row['privnodeid']] = array();
			$allcascadedata[$row['privnodeid']][] =
			   array('name' => "{$row["type"]}/{$row["name"]}/{$row["id"]}",
			         'type' => $row['privtype']);
		}
	}

	# get all privs for users with cascaded privs
	$cascadedata = array();
	foreach($nodelist as $nodeid) {
		if(array_key_exists($nodeid, $allcascadedata))
			$cascadedata[$nodeid] = $allcascadedata[$nodeid];
	}

	if($type == "resources" || $type == "all") {
		$mynodelist = $nodelist;
		# loop through each node, starting at the root
		while(count($mynodelist)) {
			$node = array_pop($mynodelist);
			# get all resource groups with block set at this node and remove any cascaded privs
			if(array_key_exists($node, $blockdata)) {
				foreach($blockdata[$node] as $name)
					unset($privs["resources"][$name]);
			}

			# get all privs for users with cascaded privs
			if(array_key_exists($node, $cascadedata)) {
				foreach($cascadedata[$node] as $data) {
					if(! array_key_exists($data['name'], $privs["resources"]))
						$privs["resources"][$data['name']] = array();
					$privs["resources"][$data['name']][] = $data["type"];
				}
			}
		}
	}
	if($type == "users" || $type == "all") {
		$mynodelist = $nodelist;
		# loop through each node, starting at the root
		while(count($mynodelist)) {
			$node = array_pop($mynodelist);
			# get all users with block set at this node and remove any cascaded privs
			$query = "SELECT CONCAT(u.unityid, '@', a.name) AS unityid "
			       . "FROM user u, "
			       .      "userpriv up, "
			       .      "userprivtype t, "
			       .      "affiliation a "
			       . "WHERE up.privnodeid = $node AND "
			       .       "up.userprivtypeid = t.id AND "
			       .       "up.userid = u.id AND "
			       .       "up.userid IS NOT NULL AND "
			       .       "t.name = 'block' AND "
			       .       "u.affiliationid = a.id";
			$qh = doQuery($query, 355);
			while($row = mysql_fetch_assoc($qh)) {
				unset($privs["users"][$row["unityid"]]);
			}

			# get all privs for users with cascaded privs
			$query = "SELECT t.name AS name, "
			       .        "CONCAT(u.unityid, '@', a.name) AS unityid "
			       . "FROM user u, "
			       .      "userpriv up, "
			       .      "userprivtype t, "
			       .      "affiliation a "
			       . "WHERE up.privnodeid = $node AND "
			       .       "up.userprivtypeid = t.id AND "
			       .       "up.userid = u.id AND "
			       .       "u.affiliationid = a.id AND "
			       .       "up.userid IS NOT NULL AND "
			       .       "t.name != 'cascade' AND "
			       .       "t.name != 'block' AND "
			       .       "up.userid IN (SELECT up.userid "
			       .                     "FROM userpriv up, "
			       .                          "userprivtype t "
			       .                     "WHERE up.userprivtypeid = t.id AND "
			       .                           "t.name = 'cascade' AND "
			       .                           "up.privnodeid = $node) "
			       . "ORDER BY u.unityid";
			$qh = doQuery($query, 356);
			while($row = mysql_fetch_assoc($qh)) {
				// if we've already seen this user, add it to the user's privs
				if(array_key_exists($row["unityid"], $privs["users"])) {
					array_push($privs["users"][$row["unityid"]], $row["name"]);
				}
				// if we haven't seen this user, create an array containing this priv
				else {
					$privs["users"][$row["unityid"]] = array($row["name"]);
				}
			}
		}
	}
	if($type == "usergroups" || $type == "all") {
		$mynodelist = $nodelist;
		# loop through each node, starting at the root
		while(count($mynodelist)) {
			$node = array_pop($mynodelist);
			# get all groups with block set at this node and remove any cascaded privs
			$query = "SELECT g.name AS groupname "
			       . "FROM usergroup g, "
			       .      "userpriv up, "
			       .      "userprivtype t "
			       . "WHERE up.privnodeid = $node AND "
			       .       "up.userprivtypeid = t.id AND "
			       .       "up.usergroupid = g.id AND "
			       .       "up.usergroupid IS NOT NULL AND "
			       .       "t.name = 'block'";
			$qh = doQuery($query, 357);
			while($row = mysql_fetch_assoc($qh)) {
				unset($privs["usergroups"][$row["groupname"]]);
			}

			# get all privs for groups with cascaded privs
			$query = "SELECT t.name AS priv, "
			       .        "g.name AS groupname, "
			       .        "g.affiliationid, "
			       .        "a.name AS affiliation, "
			       .        "g.id "
			       . "FROM userpriv up, "
			       .      "userprivtype t, "
			       .      "usergroup g "
			       . "LEFT JOIN affiliation a ON (g.affiliationid = a.id) "
			       . "WHERE up.privnodeid = $node AND "
			       .       "up.userprivtypeid = t.id AND "
			       .       "up.usergroupid = g.id AND "
			       .       "up.usergroupid IS NOT NULL AND "
			       .       "t.name != 'cascade' AND "
			       .       "t.name != 'block' AND "
			       .       "up.usergroupid IN (SELECT up.usergroupid "
			       .                      "FROM userpriv up, "
			       .                           "userprivtype t "
			       .                      "WHERE up.userprivtypeid = t.id AND "
			       .                            "t.name = 'cascade' AND "
			       .                            "up.privnodeid = $node) "
			       . "ORDER BY g.name";
			$qh = doQuery($query, 358);
			while($row = mysql_fetch_assoc($qh)) {
				// if we've already seen this group, add it to the user's privs
				if(array_key_exists($row["groupname"], $privs["usergroups"]))
					array_push($privs["usergroups"][$row["groupname"]]['privs'], $row["priv"]);
				// if we haven't seen this group, create an array containing this priv
				else 
					$privs["usergroups"][$row["groupname"]] = array('id' => $row['id'],
					                                                'affiliationid' => $row['affiliationid'],
					                                                'affiliation' => $row['affiliation'],
					                                                'privs' => array($row['priv']));
			}
		}
	}
	$_SESSION['cascadenodeprivileges'][$key] = $privs;
	return $privs;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJchangeUserPrivs()
///
/// \brief processes input for changes in users' privileges at a specific node,
/// submits the changes to the database
///
////////////////////////////////////////////////////////////////////////////////
function AJchangeUserPrivs() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("userGrant", $user["id"], $node)) {
		$text = "You do not have rights to modify user privileges at this node.";
		print "alert('$text');";
		return;
	}
	$newuser = processInputVar("item", ARG_STRING);
	$newpriv = processInputVar('priv', ARG_STRING);
	$newprivval = processInputVar('value', ARG_STRING);
	//print "alert('node: $node; newuser: $newuser; newpriv: $newpriv; newprivval: $newprivval');";

	# get cascade privs at this node
	$cascadePrivs = getNodeCascadePrivileges($node, "users");

	// if $newprivval is true and $newuser already has $newpriv
	//   cascaded to it, do nothing
	if($newprivval == 'true') {
		if(array_key_exists($newuser, $cascadePrivs['users']) &&
		   in_array($newpriv, $cascadePrivs['users'][$newuser]))
			return;
		// add priv
		$adds = array($newpriv);
		$removes = array();
	}
	else {
		// remove priv
		$adds = array();
		$removes = array($newpriv);
	}
	updateUserOrGroupPrivs($newuser, $node, $adds, $removes, "user");
	$_SESSION['dirtyprivs'] = 1;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJchangeUserGroupPrivs()
///
/// \brief processes input for changes in user group privileges at a specific
/// node, submits the changes to the database and calls viewNodes
///
////////////////////////////////////////////////////////////////////////////////
function AJchangeUserGroupPrivs() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("userGrant", $user["id"], $node)) {
		$text = "You do not have rights to modify user privileges at this node.";
		print "alert('$text');";
		return;
	}
	$newusergrpid = processInputVar("item", ARG_NUMERIC);
	$newusergrp = getUserGroupName($newusergrpid);
	$newpriv = processInputVar('priv', ARG_STRING);
	$newprivval = processInputVar('value', ARG_STRING);
	//print "alert('node: $node; newuser:grp $newuser;grp newpriv: $newpriv; newprivval: $newprivval');";

	# get cascade privs at this node
	$cascadePrivs = getNodeCascadePrivileges($node, "usergroups");

	// if $newprivval is true and $newusergrp already has $newpriv
	//   cascaded to it, do nothing
	if($newprivval == 'true') {
		if(array_key_exists($newusergrp, $cascadePrivs['usergroups']) &&
		   in_array($newpriv, $cascadePrivs['usergroups'][$newusergrp]['privs']))
			return;
		// add priv
		$adds = array($newpriv);
		$removes = array();
	}
	else {
		// remove priv
		$adds = array();
		$removes = array($newpriv);
	}
	updateUserOrGroupPrivs($newusergrpid, $node, $adds, $removes, "group");
	$_SESSION['dirtyprivs'] = 1;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJchangeResourcePrivs()
///
/// \brief processes input for changes in resource group privileges at a
/// specific node and submits the changes to the database
///
////////////////////////////////////////////////////////////////////////////////
function AJchangeResourcePrivs() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("resourceGrant", $user["id"], $node)) {
		$text = "You do not have rights to modify resource privileges at this node.";
		print "alert('$text');";
		return;
	}
	$resourcegrp = processInputVar("item", ARG_STRING);
	$newpriv = processInputVar('priv', ARG_STRING);
	$newprivval = processInputVar('value', ARG_STRING);
	//print "alert('node: $node; resourcegrp: $resourcegrp; newpriv: $newpriv; newprivval: $newprivval');";

	# get cascade privs at this node
	$cascadePrivs = getNodeCascadePrivileges($node, "resources");

	// if $newprivval is true and $resourcegrp already has $newpriv
	//   cascaded to it, do nothing
	if($newprivval == 'true') {
		if(array_key_exists($resourcegrp, $cascadePrivs['resources']) &&
		   in_array($newpriv, $cascadePrivs['resources'][$resourcegrp]))
			return;
		// add priv
		$adds = array($newpriv);
		$removes = array();
	}
	else {
		// remove priv
		$adds = array();
		$removes = array($newpriv);
	}
	$tmpArr = explode('/', $resourcegrp);
	updateResourcePrivs($tmpArr[2], $node, $adds, $removes);
	$_SESSION['dirtyprivs'] = 1;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitAddUserPriv()
///
/// \brief processes input for adding privileges to a node for a user; adds the
/// privileges
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitAddUserPriv() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("userGrant", $user["id"], $node)) {
		$text = "You do not have rights to add new users at this node.";
		print "addUserPaneHide(); ";
		print "alert('$text');";
		return;
	}
	$newuser = processInputVar("newuser", ARG_STRING);
	if(! validateUserid($newuser)) {
		$text = "<font color=red>$newuser is not a valid userid</font>";
		print setAttribute('addUserPrivStatus', 'innerHTML', $text);
		return;
	}

	$perms = explode(':', processInputVar('perms', ARG_STRING));
	$usertypes = getTypes("users");
	array_push($usertypes["users"], "block");
	array_push($usertypes["users"], "cascade");
	$newuserprivs = array();
	foreach($usertypes["users"] as $type) {
		if(in_array($type, $perms))
			array_push($newuserprivs, $type);
	}
	if(empty($newuserprivs) || (count($newuserprivs) == 1 && 
	   in_array("cascade", $newuserprivs))) {
		$text = "<font color=red>No user privileges were specified</font>";
		print setAttribute('addUserPrivStatus', 'innerHTML', $text);
		return;
	}

	updateUserOrGroupPrivs($newuser, $node, $newuserprivs, array(), "user");
	clearPrivCache();
	print "refreshPerms(); ";
	print "addUserPaneHide(); ";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitAddUserGroupPriv()
///
/// \brief processes input for adding privileges to a node for a user group;
/// adds the privileges; calls viewNodes
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitAddUserGroupPriv() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("userGrant", $user["id"], $node)) {
		$text = "You do not have rights to add new user groups at this node.";
		print "addUserGroupPaneHide(); ";
		print "alert('$text');";
		return;
	}
	$newgroupid = processInputVar("newgroupid", ARG_NUMERIC);
	# FIXME validate newgroupid

	$perms = explode(':', processInputVar('perms', ARG_STRING));
	$usertypes = getTypes("users");
	array_push($usertypes["users"], "block");
	array_push($usertypes["users"], "cascade");
	$newgroupprivs = array();
	foreach($usertypes["users"] as $type) {
		if(in_array($type, $perms))
			array_push($newgroupprivs, $type);
	}
	if(empty($newgroupprivs) || (count($newgroupprivs) == 1 && 
	   in_array("cascade", $newgroupprivs))) {
		$text = "<font color=red>No user group privileges were specified</font>";
		print setAttribute('addUserGroupPrivStatus', 'innerHTML', $text);
		return;
	}

	updateUserOrGroupPrivs($newgroupid, $node, $newgroupprivs, array(), "group");
	clearPrivCache();
	print "refreshPerms(); ";
	print "addUserGroupPaneHide(); ";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsubmitAddResourcePriv()
///
/// \brief processes input for adding privileges to a node for a resource group;
/// adds the privileges
///
////////////////////////////////////////////////////////////////////////////////
function AJsubmitAddResourcePriv() {
	global $user;
	$node = processInputVar("activeNode", ARG_NUMERIC);
	if(! checkUserHasPriv("resourceGrant", $user["id"], $node)) {
		$text = "You do not have rights to add new resource groups at this node.";
		print "addResourceGroupPaneHide(); ";
		print "alert('$text');";
		return;
	}
	$newgroupid = processInputVar("newgroupid", ARG_NUMERIC);
	$privs = array("computerAdmin", "mgmtNodeAdmin", "imageAdmin",
	               "scheduleAdmin", "serverProfileAdmin");
	$resourcesgroups = getUserResources($privs, array("manageGroup"), 1);

	if(! array_key_exists($newgroupid, $resourcesgroups['image']) &&
	   ! array_key_exists($newgroupid, $resourcesgroups['computer']) &&
	   ! array_key_exists($newgroupid, $resourcesgroups['managementnode']) &&
	   ! array_key_exists($newgroupid, $resourcesgroups['schedule']) &&
	   ! array_key_exists($newgroupid, $resourcesgroups['serverprofile'])) {
		$text = "You do not have rights to manage the specified resource group.";
		print "addResourceGroupPaneHide(); ";
		print "alert('$text');";
		return;
	}

	$perms = explode(':', processInputVar('perms', ARG_STRING));
	$privtypes = getResourcePrivs();
	$newgroupprivs = array();
	foreach($privtypes as $type) {
		if(in_array($type, $perms))
			array_push($newgroupprivs, $type);
	}
	if(empty($newgroupprivs) || (count($newgroupprivs) == 1 && 
	   in_array("cascade", $newgroupprivs))) {
		$text = "<font color=red>No resource group privileges were specified</font>";
		print setAttribute('addResourceGroupPrivStatus', 'innerHTML', $text);
		return;
	}

	updateResourcePrivs($newgroupid, $node, $newgroupprivs, array());
	clearPrivCache();
	print "refreshPerms(); ";
	print "addResourceGroupPaneHide(); ";
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn checkUserHasPriv($priv, $uid, $node, $privs, 
///                               $cascadePrivs)
///
/// \param $priv - privilege to check for
/// \param $uid - numeric id of user
/// \param $node - id of node
/// \param $privs - (optional) privileges at node
/// \param $cascadePrivs - (optional) privileges cascaded to node
///
/// \return 1 if the user has $priv at $node, 0 if not
///
/// \brief checks to see if the user has $priv at $node; if $privs
/// and $cascadePrivs are not passed in, they are looked up for $node
///
////////////////////////////////////////////////////////////////////////////////
function checkUserHasPriv($priv, $uid, $node, $privs=0, $cascadePrivs=0) {
	global $user;
	$key = getKey(array($priv, $uid, $node, $privs, $cascadePrivs));
	if(array_key_exists($key, $_SESSION['userhaspriv']))
		return $_SESSION['userhaspriv'][$key];
	if($user["id"] != $uid) {
		$_user = getUserInfo($uid, 0, 1);
		if(is_null($user))
			return 0;
	}
	else
		$_user = $user;
	$affilUserid = "{$_user['unityid']}@{$_user['affiliation']}";

	if(! is_array($privs)) {
		$privs = getNodePrivileges($node, 'users');
		$privs = getNodePrivileges($node, 'usergroups', $privs);
	}
	if(! is_array($cascadePrivs)) {
		$cascadePrivs = getNodeCascadePrivileges($node, 'users');
		$cascadePrivs = getNodeCascadePrivileges($node, 'usergroups', $cascadePrivs);
	}
	// if user (has $priv at this node) || 
	# (has cascaded $priv && ! have block at this node) return 1
	if((array_key_exists($affilUserid, $privs["users"]) &&
	   in_array($priv, $privs["users"][$affilUserid])) ||
	   ((array_key_exists($affilUserid, $cascadePrivs["users"]) &&
	   in_array($priv, $cascadePrivs["users"][$affilUserid])) &&
	   (! array_key_exists($affilUserid, $privs["users"]) ||
	   ! in_array("block", $privs["users"][$affilUserid])))) {
		$_SESSION['userhaspriv'][$key] = 1;
		return 1;
	}

	foreach($_user["groups"] as $groupid => $groupname) {
		// if group (has $priv at this node) ||
		# (has cascaded $priv && ! have block at this node) return 1
		if((array_key_exists($groupname, $privs["usergroups"]) &&
		   $groupid == $privs['usergroups'][$groupname]['id'] &&
		   in_array($priv, $privs["usergroups"][$groupname]['privs'])) ||
		   ((array_key_exists($groupname, $cascadePrivs["usergroups"]) &&
		   $groupid == $cascadePrivs['usergroups'][$groupname]['id'] &&
		   in_array($priv, $cascadePrivs["usergroups"][$groupname]['privs'])) &&
		   (! array_key_exists($groupname, $privs["usergroups"]) ||
			(! in_array("block", $privs["usergroups"][$groupname]['privs']) && 
		   $groupid == $privs['usergroups'][$groupname]['id'])))) {
			$_SESSION['userhaspriv'][$key] = 1;
			return 1;
		}
	}
	$_SESSION['userhaspriv'][$key] = 0;
	return 0;
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJpermSelectUserGroup()
///
/// \brief gets permissions granted to a user group and sends it in JSON format
///
////////////////////////////////////////////////////////////////////////////////
function AJpermSelectUserGroup() {
	global $user;
	$groups = getUserGroups(0, $user['affiliationid']);
	$groupid = processInputVar('groupid', ARG_NUMERIC);
	if(! array_key_exists($groupid, $groups)) {
		sendJSON(array('failed' => 'noaccess'));
		return;
	}
	$permdata = getUserGroupPrivs($groupid);
	$perms = array();
	foreach($permdata as $perm)
		$perms[] = $perm['permid'];
	sendJSON(array('perms' => $perms));
}

////////////////////////////////////////////////////////////////////////////////
///
/// \fn AJsaveUserGroupPrivs()
///
/// \brief saves submitted permissions for user group
///
////////////////////////////////////////////////////////////////////////////////
function AJsaveUserGroupPrivs() {
	global $user;
	$groups = getUserGroups(0, $user['affiliationid']);
	$groupid = processInputVar('groupid', ARG_NUMERIC);
	if(! array_key_exists($groupid, $groups)) {
		sendJSON(array('failed' => 'noaccess'));
		return;
	}
	$permids = processInputVar('permids', ARG_STRING);
	if(! preg_match('/^[0-9,]*$/', $permids)) {
		sendJSON(array('failed' => 'invalid input'));
		return;
	}
	$perms = explode(',', $permids);
	$query = "DELETE FROM usergrouppriv WHERE usergroupid = $groupid";
	doQuery($query, 101);
	if(empty($perms[0])) {
		sendJSON(array('success' => 1));
		return;
	}
	$values = array();
	foreach($perms as $permid)
		$values[] = "($groupid, $permid)";
	$allvals = implode(',', $values);
	$query = "INSERT INTO usergrouppriv "
	       .        "(usergroupid, "
	       .        "userprivtypeid) "
	       . "VALUES $allvals";
	doQuery($query, 101);
	sendJSON(array('success' => 1));
	$_SESSION['user']["groupperms"] = getUsersGroupPerms(array_keys($user['groups']));
}

?>
