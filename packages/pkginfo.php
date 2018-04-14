<?php

  function throw_http_error($error_number, $error_message, $extra_message = "") {
    header("Status: " . $error_number . " " . $error_message);
    print "Error " . $error_number . ": " . $error_message . "\n";
    if ($extra_message != "")
      print "<br>\n" . $extra_message;
    die();
  };

  function die_500($message) {
    throw_http_error(500, "Internal Server Error", $message);
  };

  $json_content = json_decode(
    file_get_contents(
      "https://pkgapi.arch32.tyzoid.com/package/".$_GET["repo"].":".$_GET["pkgname"]
    ),
    true
  );

  if (!isset($json_content["package"]))
    throw_http_error(404, "Package Not Found In Sync Database");

  $json_content = $json_content["package"];

  $mysql = new mysqli("localhost", "webserver", "empty", "buildmaster");
  if ($mysql->connect_error)
    die_500("Connection to database failed: " . $mysql->connect_error);

  if (! $mysql_result = $mysql -> query(
    "SELECT DISTINCT " .
    "`binary_packages`.`id`," .
    "`binary_packages`.`pkgname`," .
    "`package_sources`.`pkgbase`," .
    "CONCAT(" .
      "IF(`binary_packages`.`epoch`=\"0\",\"\",CONCAT(`binary_packages`.`epoch`,\":\"))," .
      "`binary_packages`.`pkgver`,\"-\"," .
      "`binary_packages`.`pkgrel`,\".\"," .
      "`binary_packages`.`sub_pkgrel`" .
    ") AS `version`," .
    "`repositories`.`stability` AS `repo_stability`," .
    "`repositories`.`name` AS `repo`," .
    "`architectures`.`name` AS `arch`" .
    " FROM `binary_packages`" .
    " JOIN `architectures` ON `binary_packages`.`architecture`=`architectures`.`id`" .
    " JOIN `repositories` ON `binary_packages`.`repository`=`repositories`.`id`" .
    " JOIN `build_assignments` ON `binary_packages`.`build_assignment`=`build_assignments`.`id`" .
    " JOIN `package_sources` ON `build_assignments`.`package_source`=`package_sources`.`id`" .
    " WHERE `binary_packages`.`pkgname`=from_base64(\"" . base64_encode($_GET["pkgname"]) . "\")" .
    " AND `architectures`.`name`=from_base64(\"" . base64_encode($_GET["arch"]) . "\")" .
    " AND `repositories`.`name`=from_base64(\"" . base64_encode($_GET["repo"]) . "\")"
    ))
    die_500("Query failed: " . $mysql->error);

  if ($mysql_result -> num_rows != 1)
    throw_http_error(404, "Package Not Found In Buildmaster's Database");

  $mysql_content = $mysql_result -> fetch_assoc();

  $same_keys = array (
    array("mysql" => "pkgname", "json" => "Name"),
    array("mysql" => "version", "json" => "Version"),
    array("mysql" => "repo", "json" => "Repository"),
    array("mysql" => "arch", "json" => "Architecture")
  );

  foreach ($same_keys as $same_key)
    if ($mysql_content[$same_key["mysql"]] != $json_content[$same_key["json"]])
      die_500("Inconsistency in Database found:<br>\n" .
        "buildmaster[" . $same_key["mysql"] . "] != repositories[" . $same_key["json"] . "]:<br>\n" .
        "\"" . $mysql_content[$same_key["mysql"]] . "\" != \"" . $json_content[$same_key["json"]] . "\"");

  // query _all_ dependencies

  if (! $mysql_result = $mysql -> query(
    "SELECT DISTINCT " .
    "`dependency_types`.`name` AS `dependency_type`," .
    "GROUP_CONCAT(" .
    "CONCAT(\"\\\"\",`install_target_providers`.`id`,\"\\\": \",\"{\\n\"," .
      "\"  \\\"repo\\\": \\\"\",`repositories`.`name`,\"\\\",\\n\"," .
      "\"  \\\"arch\\\": \\\"\",`architectures`.`name`,\"\\\",\\n\"," .
      "\"  \\\"pkgname\\\": \\\"\",`binary_packages`.`pkgname`,\"\\\"\\n\"," .
      "\"}\"" .
    ")) AS `deps`," .
    "`install_targets`.`name` AS `install_target`" .
    " FROM `dependencies`" .
    " JOIN `dependency_types` ON `dependency_types`.`id`=`dependencies`.`dependency_type`" .
    " JOIN `install_targets` ON `install_targets`.`id`=`dependencies`.`depending_on`" .
    " AND `install_targets`.`name` NOT IN (\"base\",\"base-devel\")" .
    " LEFT JOIN (" .
      "`install_target_providers`" .
      " JOIN `binary_packages` ON `install_target_providers`.`package`=`binary_packages`.`id`" .
      " JOIN `architectures` ON `binary_packages`.`architecture`=`architectures`.`id`" .
      " JOIN `repositories` ON `binary_packages`.`repository`=`repositories`.`id`" .
      " JOIN `repository_stability_relations` ON `repository_stability_relations`.`more_stable`=`repositories`.`stability`" .
      " AND `repository_stability_relations`.`less_stable`=" . $mysql_content["repo_stability"] .
    ") ON `install_target_providers`.`install_target`=`dependencies`.`depending_on`" .
    " WHERE `dependencies`.`dependent`=" . $mysql_content["id"] .
    " GROUP BY `install_targets`.`id`" .
    " ORDER BY FIELD (`dependency_types`.`name`,\"run\",\"make\",\"check\",\"link\")"
    ))
    die_500("Query failed: " . $mysql->error);

  $dependencies = array();
  while ($row = $mysql_result -> fetch_assoc()) {
    $row["deps"] = json_decode("{".$row["deps"]."}",true);
    $dependencies[] = $row;
  }

  function dependency_is_runtime($dep) {
    return $dep["dependency_type"]=="run";
  };

  function dependency_extract_name($dep) {
    return $dep["install_target"];
  };

  function truncate_version($name) {
    return preg_replace("[<=>].*$","",$name);
  }

  $dep_it = array_filter( $dependencies, "dependency_is_runtime");
  $dep_it = array_map("dependency_extract_name", $dep_it);
  $dep_it = array_map("truncate_version", $dep_it);
  $js_dep = array_map("truncate_version", $json_content["Depends On"]);
  $dep_errors = implode(
    ", ",
    array_diff(
      array_merge($dep_it,$js_dep),
      array_intersect($dep_it,$js_dep)
    )
  );

  if ($dep_errors != "")
    die_500("Dependencies differ: " . $dep_errors);

  // query dependent packages

  if (! $mysql_result = $mysql -> query(
    "SELECT DISTINCT " .
    "`dependency_types`.`name` AS `dependency_type`," .
    "`install_targets`.`name` AS `install_target`," .
    "GROUP_CONCAT(" .
    "CONCAT(\"\\\"\",`dependencies`.`id`,\"\\\": \",\"{\\n\"," .
      "\"  \\\"repo\\\": \\\"\",`repositories`.`name`,\"\\\",\\n\"," .
      "\"  \\\"arch\\\": \\\"\",`architectures`.`name`,\"\\\",\\n\"," .
      "\"  \\\"pkgname\\\": \\\"\",`binary_packages`.`pkgname`,\"\\\"\\n\"," .
      "\"}\"" .
    ")) AS `reqs`" .
    " FROM `install_target_providers`" .
    " JOIN `install_targets` ON `install_targets`.`id`=`install_target_providers`.`install_target`" .
    " JOIN `dependencies` ON `install_target_providers`.`install_target`=`dependencies`.`depending_on`" .
    " JOIN `dependency_types` ON `dependency_types`.`id`=`dependencies`.`dependency_type`" .
    " JOIN `binary_packages` ON `dependencies`.`dependent`=`binary_packages`.`id`" .
    " JOIN `architectures` ON `binary_packages`.`architecture`=`architectures`.`id`" .
    " JOIN `repositories` ON `binary_packages`.`repository`=`repositories`.`id`" .
    " JOIN `repository_stability_relations` ON `repository_stability_relations`.`less_stable`=`repositories`.`stability`" .
    " AND `repository_stability_relations`.`more_stable`=" . $mysql_content["repo_stability"] .
    " WHERE `install_target_providers`.`package`=" . $mysql_content["id"] .
    " GROUP BY `binary_packages`.`id`"
    " ORDER BY FIELD (`dependency_types`.`name`,\"run\",\"make\",\"check\",\"link\")"
    ))
    die_500("Query failed: " . $mysql->error);

  $dependent = array();
  while ($row = $mysql_result -> fetch_assoc()) {
    $row["reqs"] = json_decode($row["reqs"]);
    $dependent[] = $row;
  }

  $content = array_merge($mysql_content,$json_content);

  if (! $mysql_result = $mysql -> query(
    "SELECT " .
    "`binary_packages`.`pkgname` AS `pkgname`," .
    "`repositories`.`name` AS `repo`," .
    "`architectures`.`name` AS `arch`," .
    "CONCAT(" .
      "IF(`binary_packages`.`epoch`=\"0\",\"\",CONCAT(`binary_packages`.`epoch`,\":\"))," .
      "`binary_packages`.`pkgver`,\"-\"," .
      "`binary_packages`.`pkgrel`,\".\"," .
      "`binary_packages`.`sub_pkgrel`" .
    ") AS `version`" .
    " FROM `binary_packages` " .
    " JOIN `architectures` ON `binary_packages`.`architecture`=`architectures`.`id`" .
    " JOIN `repositories` ON `binary_packages`.`repository`=`repositories`.`id`" .
    " JOIN `binary_packages` AS `original`" .
    " ON `binary_packages`.`pkgname`=`original`.`pkgname`" .
    " AND `binary_packages`.`id`!=`original`.`id`" .
    " WHERE `original`.`id`=" . $mysql_content["id"]
    ))
    die_500("Query failed: " . $mysql->error);

  $elsewhere = array();
  while ($row = $mysql_result -> fetch_assoc())
    $elsewhere[] = $row;

?>
<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Arch Linux 32 - <?php print $content["Name"] . " " . $content["Version"] . " (" . $content["Architecture"]; ?>)</title>
    <link rel="stylesheet" type="text/css" href="/static/archweb.css" media="screen, projection" />
    <link rel="icon" type="image/x-icon" href="/static/favicon.ico" />
    <link rel="shortcut icon" type="image/x-icon" href="/static/favicon.ico" />
    
</head>
<body class="">
    <div id="archnavbar" class="anb-packages">
        <div id="archnavbarlogo"><h1><a href="/" title="Return to the main page">Arch Linux</a></h1></div>
        <div id="archnavbarmenu">
            <ul id="archnavbarlist">
                <li id="anb-home"><a href="https://www.archlinux32.org/">Home</a></li>
                <li id="anb-news"><a href="https://news.archlinux32.org/">News</a></li>
                <li id="anb-packages"><a href="https://packages.archlinux32.org/">Packages</a></li>
                <li id="anb-forums"><a href="https://bbs.archlinux32.org/">Forums</a></li>
                <li id="anb-bugs"><a href="https://bugs.archlinux32.org/" title="Report and track bugs">Bugs</a></li>
                <li id="anb-mailing-list"><a href="https://lists.archlinux.org/listinfo/arch-ports">Mailing List</a></li>
                <li id="anb-download"><a href="https://www.archlinux32.org/download/" title="Get Arch Linux">Download</a></li>
                <li id="anb-arch-linux-official"><a href="https://www.archlinux.org/">Arch Linux Official</a></li>
            </ul>
        </div>
    </div>
    <div id="content">
        <div id="archdev-navbar">
            
        </div>
        
        

<div id="pkgdetails" class="box">
    <h2><?php print $content["Name"]." ".$content["Version"]; ?></h2>

    <div id="detailslinks" class="listing">
        <div id="actionlist">
        <h4>Package Actions</h4>
            <ul class="small">
<!-- TODO
                <li>
                    <a href="https://projects.archlinux.org/svntogit/community.git/tree/trunk?h=packages/0ad" title="View source files for 0ad">Source Files</a> /
                    <a href="https://projects.archlinux.org/svntogit/community.git/log/trunk?h=packages/0ad" title="View changes for 0ad">View Changes</a>
                </li>
                <li>
                    <a href="https://bugs.archlinux.org/?project=5&string=0ad" title="View existing bug tickets for 0ad">Bug Reports</a> /
                    <a href="https://bugs.archlinux.org/newtask?project=5&product_category=33&item_summary=%5B0ad%5D+PLEASE+ENTER+SUMMARY" title="Report new bug for 0ad">Add New Bug</a>
                </li>
                <li><a href="https://wiki.archlinux.org/index.php/Special:Search?search=0ad" title="Search wiki for 0ad">Search Wiki</a></li>
                <li><a href="https://security.archlinux.org/package/0ad" title="View security issues for 0ad">Security Issues</a></li>
                
                <li><a href="flag/" title="Flag 0ad as out-of-date">Flag Package Out-of-Date</a>
                <a href="/packages/flaghelp/"
                    title="Get help on package flagging"
                    onclick="return !window.open('/packages/flaghelp/','FlagHelp',
                    'height=350,width=450,location=no,scrollbars=yes,menubars=no,toolbars=no,resizable=no');">(?)</a></li>
                
                <li><a href="download/" rel="nofollow" title="Download 0ad from mirror">Download From Mirror</a></li>
-->
            </ul>

            
        </div>

<?php

if (count($elsewhere)>0) {
  print "<div id=\"elsewhere\" class=\"widget\">\n";
  print "<h4>Versions Elsewhere</h4>\n";
  foreach ($elsewhere as $subst) {
    print "<ul>\n";
    print "<li><a href=\"/" . $subst["repo"] . "/" . $subst["arch"] . "/" . $subst["pkgname"] ."/\"";
    print " title=\"Package details for " . $subst["pkgname"] ."\">";
    print $subst["pkgname"] . "-" . $subst["version"] . " [" . $subst["repo"] . "] (" . $subst["arch"] . ")</a></li>\n";
    print "</ul>\n";
  }
  print "</div>\n";
}

?>
    </div>

    <div itemscope itemtype="http://schema.org/SoftwareApplication">
    <meta itemprop="name" content="<?php print $content["Name"]; ?>"/>
    <meta itemprop="version" content="<?php print $content["Version"]; ?>"/>
    <meta itemprop="softwareVersion" content="<?php print $content["Version"]; ?>"/>
    <meta itemprop="fileSize" content="<?php print $content["Download Size"]; ?>"/>
    <meta itemprop="dateCreated" content="<?php print $content["Build Date"]; ?>"/>
    <meta itemprop="datePublished" content="<?php print $content["Build Date"]; ?>"/>
    <meta itemprop="operatingSystem" content="Arch Linux 32"/>
<!-- TODO    <div style="display:none" itemprop="provider" itemscope itemtype="http://schema.org/Person">
        <meta itemprop="name" content="Levente Polyak"/>
    </div> -->
    <table id="pkginfo">
        <tr>
            <th>Architecture:</th>
            <td><a href="/packages/?arch=<?php print $content["Architecture"]; ?>"
                    title="Browse packages for <?php print $content["Architecture"]; ?> architecture"><?php print $content["Architecture"]; ?></a></td>
        </tr><tr>
            <th>Repository:</th>
            <td><a href="/packages/?repo=<?php print $content["Repository"]; ?>"
                    title="Browse the <?php print $content["Repository"]; ?> repository"><?php print $content["Repository"]; ?></a></td>
        </tr>
        
        
        
        <tr>
            <th>Description:</th>
            <td class="wrap" itemprop="description"><?php print $content["Description"]; ?></td>
        </tr><tr>
            <th>Upstream URL:</th>
            <td><a itemprop="url" href="<?php print $content["URL"]; ?>"
                    title="Visit the website for <?php print $content["Name"]; ?>"><?php print $content["URL"]; ?></a></td>
        </tr><tr>
            <th>License(s):</th>
            <td class="wrap"><?php
  if (is_array($content["Licenses"]))
    print implode(", ",$content["Licenses"]);
  else
    print $content["Licenses"];
?></td>
        </tr>
        
        <tr>
            <th>Package Size:</th>
            <td><?php print $content["Download Size"]; ?></td>
        </tr><tr>
            <th>Installed Size:</th>
            <td><?php print $content["Installed Size"]; ?></td>
        </tr><tr>
            <th>Build Date:</th>
            <td><?php print $content["Build Date"]; ?></td>
        </tr>
        
    </table>
    </div>

    <div id="metadata">
        
        <div id="pkgdeps" class="listing">
            <h3 title="<?php print $content["Name"]; ?> has the following dependencies">
                Dependencies (<?php print count($dependencies); ?>)</h3>
            <ul id="pkgdepslist">
<?php
  foreach ($dependencies as $dep) {
    if ($dep["dependency_type"]=="link")
      continue;
    print "<li>\n";
    var_dump($dep["deps"]);
//    var_dump(json_decode($dep["dep"],true));
    continue;
    if (isset($dep["pkgname"])) {
      if ($dep["pkgname"]!=$dep["install_target"]) {
        print $dep["install_target"];
        print " <span class=\"virtual-dep\">(";
      }
      print "<a href=\"/".$dep["repo"]."/".$dep["arch"]."/".$dep["pkgname"]."/\" ";
      print "title=\"View package details for ".$dep["pkgname"]."\">".$dep["pkgname"]."</a>";
      if ($dep["pkgname"]!=$dep["install_target"])
        print ")</span>";
      print "\n";
    } else {
      print "<font color=\"#ff0000\">not satisfiable dependency: \"" . $dep["install_target"] . "\"</font>\n";
    }
    if ($dep["dependency_type"]=="make")
      print "<span class=\"make-dep\"> (make)</span>\n";
    print "</li>\n";
  }
  die();
?>
<!-- TODO
<li>

</li>
<li>
libminiupnpc.so=17-64 <span class="virtual-dep">(<a href="/packages/community/x86_64/miniupnpc/" title="View package details for miniupnpc">miniupnpc</a>
)</span>

</li>
<li>
wxgtk <span class="virtual-dep">(<a href="/packages/extra/x86_64/wxgtk2/" title="View package details for wxgtk2">wxgtk2</a>
)</span>

</li>
<li>
-->
            </ul>
        </div>
        
        
        <div id="pkgreqs" class="listing">
            <h3 title="Packages that require 0ad">
                Required By (0)</h3>
            
        </div>
        
<!--        <div id="pkgfiles" class="listing">
            <h3 title="Complete list of files contained within this package">
                Package Contents</h3>
            <div id="pkgfilelist">
                <p><a id="filelink" href="files/"
                    title="Click to view the complete file list for 0ad">
                    View the file list for 0ad</a></p>
            </div>
        </div>
    </div> -->
</div>


        <div id="footer">
            <p>Copyright © 2002-2018 <a href="mailto:jvinet@zeroflux.org"
                title="Contact Judd Vinet">Judd Vinet</a> and <a href="mailto:aaron@archlinux.org"
                title="Contact Aaron Griffin">Aaron Griffin</a>.</p>

            <p>The Arch Linux name and logo are recognized
            <a href="https://wiki.archlinux.org/index.php/DeveloperWiki:TrademarkPolicy"
                title="Arch Linux Trademark Policy">trademarks</a>. Some rights reserved.</p>

            <p>The registered trademark Linux® is used pursuant to a sublicense from LMI,
            the exclusive licensee of Linus Torvalds, owner of the mark on a world-wide basis.</p>
        </div>
    </div>
    <script type="application/ld+json">
    {
       "@context": "http://schema.org",
       "@type": "WebSite",
       "url": "/",
       "potentialAction": {
         "@type": "SearchAction",
         "target": "/packages/?q={search_term}",
         "query-input": "required name=search_term"
       }
    }
    </script>
    
</body>
</html>
