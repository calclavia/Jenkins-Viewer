<?php
/**
 * Plugin Name: Jenkins Viewer
 * Plugin URI: http://calclavia.com
 * Description: A viewer for Jenkins jobs installed locally on this server.
 * Version: 1.0.0
 * Author: Calclavia
 * Author URI: http://calclavia.com
 * License: LGPL3
 */

class Build
{
	public $jobName = "";

	public $jobDirectory = "";

	/**
	 * The build number.
	 */
	public $number = 0;

	/**
	 * The stability value for the build. 0 - Fail, 1 - Successful, 2 - Promoted.
	 */
	public $stability = 0;

	/**
	 * Description of that specific build. Includes changes and information.
	 */
	public $description = "";

	/**
	 * Description of what this specific build of the software depends on.
	 */
	public $dependency = "";

	/**
	 * Each artifact file URL will be stored here avaliable for download.
	 */
	public $artifacts = array();

	/**
	 * Outputs the build as a row in an HTML table.
	 */
	public function getAsRow()
	{
		$collum = array();
		$collum[] = "#" . $this -> number;
		$collum[] = $this -> dependency;
		$collum[] = $this -> getArtifactsAsDiv();

		return $collum;
	}

	public function getArtifactsAsDiv()
	{
		$downloadFile = get_option('jenkins_viewer_download_file');
		$outputDir = get_option('jenkins_viewer_output_directory');
		$buttonClass = "btn-default";

		//Check and see if this build is promoted.
		if ($this -> stability == 2)
		{
			$class = "build_promoted";
			$buttonClass = "btn-success";
		}

		$artifactHTML = "<div>";

		//Loop through each artifact
		foreach ($this->artifacts as $artifact)
		{
			if ($artifact && !empty($artifact))
			{
				if (strstr($artifact, ".jar"))
				{
					$fileNameData = explode(".jar", $artifact);
				}
				else
				{
					$fileNameData = explode(".zip", $artifact);
				}
                
                $requestFile = $this -> jobDirectory. "builds/{$this->number}/{$outputDir}/{$artifact}";

				if($downloadFile)
				{
                    //Encodes the request with the job name and the request file.
					$url = $downloadFile . "?name=". rawurlencode($this->jobName) ."&r=" . rawurlencode($requestFile);
				}
				else 
				{
					$url = $requestFile;	
				}
				
				$artifactHTML .= "<a class='build-link btn $buttonClass' href='$url' target='_blank'>" . $fileNameData[0] . "</a> ";
			}
		}

		$artifactHTML .= "</div>";

		return $artifactHTML;
	}

}

function getFilesInDir($path)
{
	$files = array();

	if ($handle = opendir($path))
	{
		while (false !== ($entry = readdir($handle)))
		{
			if ($entry != "." && $entry != "..")
			{
				$folder = $path . $entry;

				if (!is_link($folder))
				{
					$files[] = $entry;
				}
			}
		}
	}
	closedir($handle);

	return $files;
}

function parse_properties($txtProperties)
{
	$result = array();

	$lines = explode("\n", $txtProperties);
	$key = "";

	$isWaitingOtherLine = false;
	foreach ($lines as $i => $line)
	{

		if (empty($line) || (!$isWaitingOtherLine && strpos($line, "#") === 0))
			continue;

		if (!$isWaitingOtherLine)
		{
			$key = substr($line, 0, strpos($line, '='));
			$value = substr($line, strpos($line, '=') + 1, strlen($line));
		}
		else
		{
			$value .= $line;
		}

		/* Check if ends with single '\' */
		if (strrpos($value, "\\") === strlen($value) - strlen("\\"))
		{
			$value = substr($value, 0, strlen($value) - 1) . "\n";
			$isWaitingOtherLine = true;
		}
		else
		{
			$isWaitingOtherLine = false;
		}

		$result[$key] = $value;
		unset($lines[$i]);
	}

	return $result;
}

function readBuilds($jobName)
{
	$jobUrl = get_option('jenkins_viewer_directory');
	$jobGlobalUrl = get_option('jenkins_viewer_url');
	$buildDir = $jobUrl . $jobName . "/builds/";
	$outputDir = get_option('jenkins_viewer_output_directory');

	$buildFolders = getFilesInDir($buildDir);

    if(count($buildFolders) > 0 && $buildFolders)
	{
		//An array of builds to be displayed.
		$builds = array();
	
		foreach ($buildFolders as $buildFolder)
		{
			$buildXML = $buildDir . $buildFolder . "/build.xml";
	
			if (file_exists($buildXML))
			{
				//Read build.xml file from Jenkins
				$xml = simplexml_load_file($buildXML);
	
				$build = new Build();
				$build -> jobName = $jobName;
				$build -> jobDirectory = $jobGlobalUrl . $jobName . "/";
				$build -> number = intval($xml -> number);
	
				if ($xml -> result == "SUCCESS")
				{
					$build -> stability = 1;
					
					$promotedBuild = $xml -> actions->{'hudson.plugins.promoted__builds.PromotedBuildAction'};
	
					if (isset($promotedBuild))
					{
						if (isset($promotedBuild -> statuses))
						{
							if (isset($promotedBuild -> statuses -> {'hudson.plugins.promoted__builds.Status'}))
							{
								if (isset($promotedBuild -> statuses -> {'hudson.plugins.promoted__builds.Status'} -> promotion))
								{
									$build -> stability = 2;
								}
							}
						}
					}
				}
	
				//Get Artifacts
				$artifactFolder = $buildDir . $buildFolder . "/" . $outputDir;

                if (file_exists($artifactFolder))
				{
					$artifacts = getFilesInDir($artifactFolder);
	
					foreach ($artifacts as $artifact)
					{
						//Read build properties file.
						if ($artifact == "build.properties")
						{
							$interpretationString = get_option('jenkins_viewer_build_properties');
							$properties = parse_properties(file_get_contents($artifactFolder . "/" . $artifact));
							if($properties && !empty($interpretationString))
							{
								$interpretations = explode(";", $interpretationString);
                                $isFirst = true;
                                
								foreach($interpretations as $interpretation)
								{
									if(!empty($interpretation))
									{
										$interpretation = explode("=", $interpretation, 2);
										if (isset($properties[$interpretation[0]]))
										{
                                            if(!$isFirst)
                                            {
                                                $build -> dependency .= ", ";
                                            }

											$build -> dependency .= str_replace("%1%", $properties[$interpretation[0]], $interpretation[1]);
                                            
                                            if(!empty($build -> dependency))
                                            {
                                                $isFirst = false;
                                            }
										}
									}
								}
							}
						}
						else
						{
							$build -> artifacts[] = $artifact;
						}
					}
	
					sort($build -> artifacts);
					
					//Get Description, read changelog
					$changelogFile = $buildDir . $buildFolder . "/changelog.xml";
					//$build -> description = date("F j, Y", intval($xml->startTime));
					$changelogXML = file_get_contents($changelogFile);
					$lines = explode("\n", $changelogXML);
					$read = false;
					$isDescriptionEmpty = true;
					
					$build -> description = "<ul>";
					
					foreach($lines as $line)
					{
						if (strpos($line, 'committer') !== false)
						{
							$read = !$read;
							continue;
						}
						
						if($line != "" && !empty($line) && $read)
						{
							$lineToAdd = "<li>". ucfirst(trim($line)) ."</li>";
							
							if (!strpos($build -> description, $lineToAdd) !== false)
							{
								$build -> description .= $lineToAdd;
								$isDescriptionEmpty = false;
							}
							
							$read = false;
						}
					}
					
					$build -> description .= "</ul>";
					
					if($isDescriptionEmpty)
					{
						$build -> description = "";
					}
					
					$build -> description = trim($build -> description);
				}
	
				$builds[] = $build;
			}
		}
	
		//Sort builds by build number.
		usort($builds, function($a, $b)
		{
			if ($a -> number == $b -> number)
			{
				return 0;
			}
			else if ($a -> number > $b -> number)
			{
				return -1;
			}
			else
			{
				return 1;
			}
		});
	
        /**
         * Build Table
         */
        $htmlTable = '<div class="table-responsive"><table class="table table-striped jenkins-build-table">';
        $htmlTable .= "<thead><tr onclick=\"$('.jenkins-build-table').children('.changelog').toggle();\"><td>#</td><td>Dependency</td><td>Artifacts</td><td style='cursor:pointer' align='right'>Changelog<b class='caret'></b></td></tr></thead>";
        
		foreach ($builds as $build)
		{
			if ($build -> stability > 0 && count($build -> artifacts) > 0)
            {
				if(!empty($build -> description))
				{
                    $htmlTable .= "<tr onclick='$(this).next().stop(true, true).fadeToggle()'>";

                    foreach($build -> getAsRow() as $column)
                    {
                        $htmlTable .= "<td>";
                        $htmlTable .= $column;
                        $htmlTable .= "</td>";
                    }
                    
                    $htmlTable .= "<td style='cursor:pointer' align='right'><b class='caret'></b></td>";
                    $htmlTable .= "</tr>";
                    
                    $htmlTable .= "<tr class='changelog' style='display:none'>";
                    $htmlTable .= "<td colspan='4'>" . $build -> description . "</td>";
                    $htmlTable .= "</tr>";
				}
				else
				{
                    $htmlTable .= "<tr>";
                    
                    foreach($build -> getAsRow() as $column)
                    {
                        $htmlTable .= "<td>";
                        $htmlTable .= $column;
                        $htmlTable .= "</td>";
                    }
                    
                    $htmlTable .= "</tr>";
				}
			}
		}
        
        $htmlTable .= "</table></div>";
		return $htmlTable;
	}

	return "<p>Failed to find Jenkins build data for '$jobName'. Please check again later!</p>";
}

/**
 * WordPress Shortcode
 * Usage: [jenkins job="JOB_NAME"]
 */
function jenkins_filter($atts)
{
    return readBuilds($atts['job']);
}

add_shortcode( 'jenkins', 'jenkins_filter' );

/**
 * Wordpress Admin Menu
 */
add_option("jenkins_viewer_directory", "/var/lib/jenkins/jobs/");
add_option("jenkins_viewer_url",  $GLOBALS['base_url']);
add_option("jenkins_viewer_output_directory", "archive/output");
add_option("jenkins_viewer_download_file", $GLOBALS['base_url']."/download.php");
add_option("jenkins_viewer_build_properties", "version.dependency=%1%;");


function jk_settings_api_init()
{
    // Add the section to reading settings so we can add our
    // fields to it
    add_settings_section(
        'jk_setting_section',
        'Jenkins Viewer Settings',
        'jk_setting_section_callback_function',
        'general'
    );
    
    // Add the field with the names and function to use for our new
    // settings, put it in our new section
    add_settings_field(
        'jk_setting_directory',
        'Jenkins Job Directory',
        'jk_setting_directory',
        'general',
        'jk_setting_section'
    );
    
    add_settings_field(
        'jk_setting_url',
        'Jenkins Base URL',
        'jk_setting_url',
        'general',
        'jk_setting_section'
    );
    
    add_settings_field(
        'jk_setting_output_directory',
        'Jenkins Relative Output Directory',
        'jk_setting_output_directory',
        'general',
        'jk_setting_section'
    );
    
    add_settings_field(
        'jk_setting_download_file',
        'Jenkins Download Get Hit',
        'jk_setting_download_file',
        'general',
        'jk_setting_section'
    );
    
    add_settings_field(
        'jk_setting_build_parse',
        'Jenkins Build URL',
        'jk_setting_build_parse',
        'general',
        'jk_setting_section'
    );
    
    // Register our setting so that $_POST handling is done for us and
    // our callback function just has to echo the <input>
    register_setting('general', 'jenkins_viewer_directory');
    register_setting('general', 'jenkins_viewer_url');
    register_setting('general', 'jenkins_viewer_output_directory');
    register_setting('general', 'jenkins_viewer_download_file');
    register_setting('general', 'jenkins_viewer_build_properties');
}

add_action( 'admin_init', 'jk_settings_api_init' );

/**
 * Call Back Functions
 */
function jk_setting_section_callback_function() 
{
 	echo '<p>This is the configuration page for the Jenkins Viewer plugin.</p>';
}
 
function jk_setting_directory()
{
 	echo '<input name="jenkins_viewer_directory" type="text" value="' . get_option( 'jenkins_viewer_directory' ) . '" /> The internal directory in which Jenkins is installed in.';
}

function jk_setting_url()
{
 	echo '<input name="jenkins_viewer_url" type="text" value="' . get_option( 'jenkins_viewer_url' ) . '" /> The Jenkins directory in which all jobs and builds are stored inside, usually named as "jobs". You may use symbolic links to make this directory accessable. This will be posted as a GET parameter when we hit your download php file.';
}

function jk_setting_output_directory()
{
 	echo '<input name="jenkins_viewer_output_directory" type="text" value="' . get_option( 'jenkins_viewer_output_directory' ) . '" /> The directory to search for artifacts relative to the build directory. No trailing slash or beginning with a slash.';
}

function jk_setting_download_file()
{
 	echo '<input name="jenkins_viewer_download_file" type="text" value="' . get_option( 'jenkins_viewer_download_file' ) . '" /> (Optional, leaving this blank will default to the Jenkins URL) The url to send a artifact download link to. The file will be passed with an "r" get variable specifiying the URL of the download. Leave this for a direct link.';
}

function jk_setting_build_parse()
{
 	echo '<input name="jenkins_viewer_build_properties" type="text" value="' . get_option( 'jenkins_viewer_build_properties' ) . '" /> Replaces and interpretes the build.properties file in the following manner.';
}
?>