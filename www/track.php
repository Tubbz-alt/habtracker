<?php

    session_start();
    $pagetitle="APRS:  Map";
    $documentroot = $_SERVER["DOCUMENT_ROOT"];
    include_once $documentroot . '/common/functions.php';
    include_once $documentroot . '/common/sessionvariables.php';
    include_once $documentroot . '/common/logo.php';


    if (isset($_GET["followfeatureid"]))  {
        $get_followfeatureid=$_GET["followfeatureid"];
        $pagetitle = "APRS:  " .  $get_followfeatureid;
    }
    else
        $get_followfeatureid = "";

    if (isset($_GET["showallstations"]))
        $get_showallstations = 1;
    else
        $get_showallstations = 0;
    
    if (isset($_GET["latitude"]))
        $get_latitude = $_GET["latitude"];
    else 
        $get_latitude = "";

    if (isset($_GET["longitude"]))
        $get_longitude = $_GET["longitude"];
    else 
        $get_longitude = "";

    if (isset($_GET["zoom"]))
        $get_zoom = $_GET["zoom"];
    else 
        $get_zoom = "";



    $link = connect_to_database();
    if (!$link) {
        db_error(sql_last_error());
        return 0;
    }

    $query = 'select f.flightid from flights f where f.active = true order by f.flightid desc;';
    $result = sql_query($query);
    if (!$result) {
        db_error(sql_last_error());
        sql_close($link);
        return 0;
    }
 
/*
    if (sql_num_rows($result) <= 0) {
        sql_close($link);
        return 0;
    }
*/

    $object = [];
    $output = [];
    $flightlist = [];
    $flightlist = sql_fetch_all($result);
    $numflights = sql_num_rows($result);
    

    while($row = sql_fetch_array($result)) {
        $flightid = $row["flightid"];
        $query2 = 'select fm.callsign from flightmap fm where fm.flightid = $1 order by fm.callsign desc;';
        $result2 = pg_query_params($link, $query2, array($flightid));
        if (!$result2) {
            db_error(sql_last_error());
            sql_close($link);
            return 0;
        }
        
        $object = [];
        $callsigns = [];
        while ($row2 = sql_fetch_array($result2)) {
            $callsigns[] = $row2["callsign"];
        }
        $object["flightid"] = $flightid;
        $object["callsigns"] = $callsigns;
        $output[] = $object;
    }


    include $documentroot . '/common/header-map.php';

?>
<script>

    var flightids = <?php echo json_encode($output); ?>;
    var mycallsign = "<?php echo $mycallsign; ?>";
    var lookbackperiod = "<?php echo $lookbackperiod; ?>";
    var iconsize = "<?php echo $iconsize; ?>";
    var plottracks = "<?php echo $plottracks; ?>";
 
    var followfeatureid = "<?php echo $get_followfeatureid; ?>";
    var showallstations = "<?php echo $get_showallstations; ?>";
    var latitude = "<?php echo $get_latitude; ?>";
    var longitude = "<?php echo $get_longitude; ?>";
    var zoom = "<?php echo $get_zoom; ?>";
    var map;
    var canvasRender;
    var pathsPane;
    var flightPane;
    var flightTooltipPane;
    var otherTooltipPane;
    var otherStationsPane;
    var lastposition;
    var activeflights = [];

    // these are for the Live Packet Stream tab
    var updateLivePacketStreamEvent;
    var packetdata;
    var currentflight;
    var livePacketStreamState = 0;;
    var processInTransition = 0;

</script>
<script src="/common/map.js"></script>
<script>


    /***********
    * changeAssignedFlight function
    *
    * This function will update the assigned flight for a team (ex. Alpha, Bravo, etc.)
    ***********/
    function changeAssignedFlight(tactical, element) {
        var assignedFlight = element.options[element.selectedIndex].value;

        //document.getElementById("error").innerHTML = "tactical:  " + tactical + "  flight: " + assignedFlight;

        $.get("changeassignedflight.php?tactical=" + tactical + "&flightid=" + assignedFlight, function(data) {
            document.getElementById("newtrackererror").innerHTML = "";
            getTrackers();
        });
    }

    /***********
    * updateTrackerTeam function
    *
    * This function will update a tracker's team assignment
    ***********/
    function changeTrackerTeam(call, element) {
        var tactical = element.options[element.selectedIndex].value;

        //document.getElementById("error").innerHTML = "callsign:  " + call + "  tactical:  " + tactical;

        $.get("changetrackerteam.php?callsign=" + call + "&tactical=" + tactical, function(data) {
            document.getElementById("newtrackererror").innerHTML = "";
            getTrackers();
        });
    }


    /***********
    * getTrackers function
    *
    * This function queries the backend for the list of flights, then the list of trackers and their current flight assignments
    * ...then will create the table for displaying the tracking teams
    ***********/
function getTrackers() {
    $.get("getflights.php", function(fdata) {
        var flightsJson = JSON.parse(fdata)
        var flightids = [];
        var f;

        for (f in flightsJson) {
            flightids.push(flightsJson[f].flight);
        }

        $.get("getteams.php", function(data) {
            var teamsJson = JSON.parse(data);
            var teams = [];
            var t;

            for (t in teamsJson) {
                teams.push(teamsJson[t].tactical);
            }


            $.get("gettrackers.php", function(data) {
                var trackerJson = JSON.parse(data);
                var keys = Object.keys(trackerJson);
                var i; 
                var j;
                var k;
                var teamhtml;

                //Create a HTML Table element.
                var table = document.createElement("TABLE");
                var tablediv = document.getElementById("trackers");
                table.setAttribute("class", "packetlist");
                table.setAttribute("style", "width: auto");
 
                //The columns
                var columns = ["Team and Flight Assignment", "Callsign", "Notes", "Move to This Team"];
     
                //Add the header row.
                var row = table.insertRow(-1);
                for (i = 0; i < columns.length; i++) {
                    var headerCell = document.createElement("TH");
                    headerCell.innerHTML = columns[i];
                    headerCell.setAttribute("class", "packetlistheader");
                    row.appendChild(headerCell);
                }


                //Add the data rows.
                for (i = 0; i < keys.length; i++) {
                    row = table.insertRow(-1);
                    var trackers = trackerJson[i].trackers;
                    var trackerkeys = Object.keys(trackers);
                    var teamcell = row.insertCell(0);
                    var flight;
                    var html = "<select id=\"" + trackerJson[i].tactical + "\" onchange='changeAssignedFlight(\"" + trackerJson[i].tactical + "\", this)'>";
                    var checked;
                    var foundmatch = 0;
   

                    teamcell.setAttribute("class", "packetlist");
                    if (i % 2)
                        teamcell.setAttribute("style", "background-color: lightsteelblue; white-space: normal; word-wrap: word-break;"); 
 
 
                    for (flight in flightids) {
                        if (flightids[flight] == trackerJson[i].flightid) {
                            checked = "selected=\"selected\""; 
                            foundmatch = 1;
                        }
                        else
                            checked = "";
                        html = html + "<option value=" + flightids[flight] + " " + checked + " >" + flightids[flight] + "</option>";
                    }
                    if (trackerJson[i].flightid == "At Large" || foundmatch == 0)
                        checked = "selected=\"selected\""; 
                    else
                        checked = "";
                    html = html + "<option value=\"atlarge\" " + checked + " >At Large</option></select>";
         
                    teamcell.innerHTML = "<span style=\"font-size: 1.2em;\"><strong>" + trackerJson[i].tactical + "</strong></span><br>" + html;
                    teamcell.setAttribute("rowspan", trackerkeys.length);
                  
                    var t;
    
                    for (j = 0; j < trackerkeys.length; j++) {
                        if (j > 0) {
                            row = table.insertRow(-1);
                        }
                        teamhtml = "<select id=\"" + trackers[j].callsign + "_tacticalselect\" onchange='changeTrackerTeam(\"" + trackers[j].callsign + "\", this)'>";
                        for (t in teams) {
                           if (trackerJson[i].tactical == teams[t])
                               checked = "selected=\"selected\""; 
                            else
                                checked = "";
                            teamhtml = teamhtml + "<option value=\"" + teams[t] + "\" " + checked + " >" + teams[t] + "</option>";
                        }
                        teamhtml = teamhtml + "</select>";
    
                        var cellCallsign = row.insertCell(-1);
                        cellCallsign.setAttribute("class", "packetlist");
                        if (i % 2)
                            cellCallsign.setAttribute("style", "background-color: lightsteelblue;"); 
                        cellCallsign.innerHTML = trackers[j].callsign;
    
                        var cellNotes = row.insertCell(-1);
                        cellNotes.setAttribute("class", "packetlist");
                        cellNotes.setAttribute("style", "white-space: normal; word-wrap: break-word;"); 
                        if (i % 2)
                            cellNotes.setAttribute("style", "background-color: lightsteelblue; white-space: normal; word-wrap: break-word;"); 
                        cellNotes.innerHTML = trackers[j].notes;
    
                        var cellFlightid = row.insertCell(-1);
                        cellFlightid.setAttribute("class", "packetlist");
                        if (i % 2)
                            cellFlightid.setAttribute("style", "background-color: lightsteelblue;"); 
                        cellFlightid.innerHTML = teamhtml;
    
                    }
                }
                tablediv.innerHTML = "";
                tablediv.appendChild(table);
            });
        });
    });
}



    /***********
    * startUpProcesses
    *
    * This function will submit a request to the backend web system to start the various daemons for the system.
    ***********/
    function startUpProcesses() {
        if (!processInTransition) {
            processInTransition = 1;
            var startinghtml = "<mark>Starting...</mark>";
            $("#systemstatus").html(startinghtml);
            $.get("startup.php", function(data) {
                var startedhtml = "<mark>Started.</mark>";
                $("#systemstatus").html(startedhtml)
                getProcessStatus();
                processInTransition = 0;
            });;
        }
    }


    /***********
    * shutDownProcesses
    *
    * This function will submit a request to the backend web system to kill/stop the various daemons for the system.
    ***********/
    function shutDownProcesses() {
        if (!processInTransition) {
            processInTransition = 1;
            var stoppinghtml = "<mark>Shutting down...</mark>";
            $("#systemstatus").html(stoppinghtml);
            $.get("shutdown.php", function(data) { 
                var stoppedhtml = "<mark>Stopped.</mark>";
                $("#systemstatus").html(stoppedhtml)
                getProcessStatus();
                processInTransition = 0;
            });
        }
    }


    /***********
    * updateLivePacketStream function
    *
    * This function queries the lastest packets heard (from anywhere)
    ***********/
    function updateLivePacketStream() {
        if (livePacketStreamState)
            document.dispatchEvent(updateLivePacketStreamEvent); 
    }


    /***********
    * clearLivePacketFilters function
    *
    * This function clears the filter fields on the Live Packet Stream tab 
    ***********/
    function clearLivePacketFilters() {
        document.getElementById("searchfield").value = "";
        document.getElementById("searchfield2").value = "";
        document.getElementById("operation").selectedIndex = 0;
        document.getElementById("packetdata").innerHTML = "";
        document.getElementById("packetcount").innerHTML = "0";
        updateLivePacketStream();
    }


    /***********
    * displayLivePackets function
    *
    * This function updates the Live Packet Stream tab with new packets after applying any user defined filters
    ***********/
    function displayLivePackets() {
        var packets = JSON.parse(packetdata);
        var html = "";
        var keys = Object.keys(packets);
        var key;
        var searchstring = document.getElementById("searchfield").value;
        var searchstring2 = document.getElementById("searchfield2").value;
        var operation = document.getElementById("operation").value;
        var i = 0;
 
        //document.getElementById("debug").innerHTML = operation;
        for (key in keys) {
           if (operation == "and") {
               if (packets[key].packet.toLowerCase().indexOf(searchstring.toLowerCase()) >= 0 &&
                   packets[key].packet.toLowerCase().indexOf(searchstring2.toLowerCase()) >= 0) {
                   html = html + packets[key].packet + "<br>"; 
                   i += 1;
               }
           }
           else if (operation == "or") {
               //document.getElementById("debug").innerHTML = "in OR section";
               if (packets[key].packet.toLowerCase().indexOf(searchstring.toLowerCase()) >= 0 || 
                   packets[key].packet.toLowerCase().indexOf(searchstring2.toLowerCase()) >= 0) {
                   html = html + packets[key].packet + "<br>"; 
                   i += 1;
               }
           }
           else if (operation == "not") {
               //document.getElementById("debug").innerHTML = "in OR section";
               if (searchstring.length > 0 && searchstring2.length > 0) {
                   if (packets[key].packet.toLowerCase().indexOf(searchstring.toLowerCase()) >= 0 && 
                       packets[key].packet.toLowerCase().indexOf(searchstring2.toLowerCase()) < 0) {
                       html = html + packets[key].packet + "<br>"; 
                       i += 1;
                   }
               }
               else if (searchstring.length > 0) {
                   if (packets[key].packet.toLowerCase().indexOf(searchstring.toLowerCase()) >= 0) {
                       html = html + packets[key].packet + "<br>"; 
                       i += 1;
                   }
               }
               else if (searchstring2.length > 0) {
                   if (packets[key].packet.toLowerCase().indexOf(searchstring2.toLowerCase()) < 0) {
                       html = html + packets[key].packet + "<br>"; 
                       i += 1;
                   }
               }
               else {
                   html = html + packets[key].packet + "<br>"; 
                   i += 1;
               }
               
           }

        }
        document.getElementById("packetdata").innerHTML = html;
        document.getElementById("packetcount").innerHTML = i.toLocaleString();
    }


    /***********
    * getLivePackets function
    *
    * This function gets the most recent live packets for the Live Packet Stream tab
    ***********/
    function getLivePackets() {
      if (livePacketStreamState) {
          var url;

          if (currentflight == "allpackets")
              url = "getallpackets.php";
          else
              url = "getpackets.php?flightid=" + currentflight;
 
          packetdata = {};
          $.get(url, function(data) { 
              packetdata = data;
              updateLivePacketStream(); 
          });
        }
    }


    /***********
    * selectedflight function
    *
    * This function gets the currently selected flight for the Live Packet Stream tab
    ***********/
    function selectedflight() {
        var radios = document.getElementsByName("flightLivePacketStream");
        var selectedValue;

        for(var i = 0; i < radios.length; i++) {
            if(radios[i].checked) selectedValue = radios[i].value;   
        }
        return selectedValue;
    }



    /***********
    * getProcessStatus function
    *
    * This function queries the status of processes
    ***********/
    function getProcessStatus() {
      $.get("getstatus.php", function(data) {
          var statusJson = JSON.parse(data);
          var keys = Object.keys(statusJson.processes);
          var i = 0;
          var k = 0;

          /* Loop through the processes and update their status */
          for (i = 0; i < keys.length; i++) {
              document.getElementById(statusJson.processes[i].process + "-status").innerHTML = "<mark style=\"background-color:  " + (statusJson.processes[i].status > 0 ? "lightgreen;\">[Okay]" : "red;\">[Not okay]") + "</mark>";
              k += statusJson.processes[i].status;
          }

          var donehtml = "<mark>Not running.</mark>";
          if (statusJson.rf_mode == 1 && k >= keys.length)
              donehtml = "<mark style=\"background-color: lightgreen;\">Running.</mark>";
          if (statusJson.rf_mode == 0 && k >= keys.length-1)
              donehtml = "<mark style=\"background-color: lightgreen;\">Running in online mode.</mark>";
          $("#systemstatus").html(donehtml);
      });
    }


    /***********
    * initialize function
    *
    * This function performs all of the heavy lifting to init the map, get preferences, and start things up.
    ***********/
    function initialize() {
        var baselayer;
        var overlays;


	// create the tile layer referencing the local system as the url (i.e. "/maps/....")
	var osmUrl='/maps/{z}/{x}/{y}.png';
	//var osmUrl='https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
	var osmAttrib='Map data © <a href="https://openstreetmap.org">OpenStreetMap</a> contributors';
        var tilelayer = L.tileLayer(osmUrl, {minZoom: 4, maxZoom: 20, attribution: osmAttrib});
        //var graytilelayer = L.tileLayer.grayscale(osmUrl, {minZoom: 4, maxZoom: 20, attribution: osmAttrib, });

        // Create a canvas for ploting balloon markers (those little "+" signs)
        //canvasRenderer = L.canvas({padding: 0.5, tolerance: 5});

        // Create a map object. 
	map = new L.Map('map', {
            //renderer : canvasRenderer,
            preferCanvas:  true,
            zoomControloption: false,
            layers : [ tilelayer ]
        });

        tilelayer.addTo(map);		

        // Pane for all tracks, to put them at the bottom of the z-order
        pathsPane = map.createPane("pathsPane");
        pathsPane.style.zIndex = 300; 

        // Pane for all flights, to put them at the top of the z-order
        flightPane = map.createPane("flightPane");
        flightPane.style.zIndex = 670; 

        // Pane for all flight Tooltips
        flightTooltipPane = map.createPane("flightTooltipPane");
        flightTooltipPane.style.zIndex = 680; 

        // Pane for all non-flight tooltips, to put them underneath regular tooltips
        otherTooltipPane = map.createPane("otherTooltipPane");
        otherTooltipPane.style.zIndex = 640; 

        // Pane for all other stations, to put them underneath regular markers/objects
        otherStationsPane = map.createPane("otherStationsPane");
        otherStationsPane.style.zIndex = 590; 

 
        if (latitude != "" && longitude != "" && zoom != "")
	    map.setView(new L.LatLng(latitude, longitude), zoom);
        else
	    map.setView(new L.LatLng(lastposition.geometry.coordinates[1], lastposition.geometry.coordinates[0]), 12);
        
        //document.getElementById("error").innerHTML = "lat:  " + latitude + "   Long:  " + longitude + "  zoom:  " + zoom;

        // Layer groups for all stations and just my station.  This allows toggling the visibility of these two groups of objects.
        var allstations = L.layerGroup();
        var mystation = L.layerGroup();
        // Layer group for trackers that are not assigned to a specific flight
        var trackersatlarge = L.layerGroup();

        var a = createRealtimeLayer("getallstations.php?lookbackperiod=" + lookbackperiod + "&plottracks=" + plottracks, allstations, 5 * 1000, function(){ return { color: 'black'}});
        if (showallstations == 1)
            a.addTo(map); 

        createRealtimeLayer("getmystation.php?lookbackperiod=" + lookbackperiod, mystation, 5 * 1000, function(){ return { color: 'black'}}).addTo(map);
        createRealtimeLayer("gettrackerstations.php?lookbackperiod=" + lookbackperiod, trackersatlarge, 5 * 1000, function(){ return { color: 'black'}}).addTo(map);

        // The base layers and overlays that will be added to every map
        var groupedoverlays = { 
            "Generic Stations" : {
                "All Other Stations" : allstations, 
                "Trackers at Large" : trackersatlarge, 
                "My Location" : mystation
            }
        };
        baselayer = { "OSM Base Map" : tilelayer };
 
        // use the grouped layers plugin so the layer selection widget shows layers categorized
        var layerControl = L.control.groupedLayers(baselayer, groupedoverlays, { groupCheckboxes: true}).addTo(map); 

        // This fixes the layer control such that when used on a touchable device (phone/tablet) that it will scroll if there are a lot of layers.
        if (!L.Browser.touch) {
            L.DomEvent
            .disableClickPropagation(layerControl._container)
            .disableScrollPropagation(layerControl._container);
         } 
         else {
             L.DomEvent.disableClickPropagation(layerControl._container);
         }



        // add a sidebar pane for navigation and instrumentation
        var sidebar = L.control.sidebar('sidebar').addTo(map);
        var zoomcontrol = L.control.zoom({ position: 'topright' }).addTo(map);

        /*
        * This sets up all the flight layers.
        *   ...includes the active, predicted, and landing layers
        */
        var key;
        var key2;
            
        for (key in flightids) {
            //document.getElementById("error").innerHTML = JSON.stringify(flightids[key]);
            var predictedpathlayer = L.layerGroup();
            var landingpredictionlayer = L.layerGroup();
            var trackerstationslayer = L.layerGroup();
    

            for (key2 in flightids[key].callsigns) {
                var activeflightlayer = L.featureGroup();

                //activeflights.push({ "callsign" : flightids[key].callsigns[key2], "layergroup" : activeflightlayer });
                createActiveFlightsLayer("getactiveflights.php?flightid=" + flightids[key].flightid + "&callsign=" + flightids[key].callsigns[key2] + "&lookbackperiod=" + lookbackperiod, activeflightlayer, 5 * 1000).addTo(map);
                layerControl.addOverlay(activeflightlayer, flightids[key].callsigns[key2], "Flight:  " + flightids[key].flightid);
            }
    
            
            createRealtimeLayer("gettrackerstations.php?flightid=" + flightids[key].flightid + "&lookbackperiod=" + lookbackperiod, trackerstationslayer, 5 * 1000, function(){ return { color: 'black'}}).addTo(map);
            createFlightPredictionLayer("getpredictionpaths.php?flightid=" + flightids[key].flightid +  "&lookbackperiod=" + lookbackperiod, predictedpathlayer, 5 * 1000);
            createLandingPredictionsLayer("getlandingpredictions.php?flightid=" + flightids[key].flightid + "&lookbackperiod=" + lookbackperiod, landingpredictionlayer, 5 * 1000).addTo(map);
            layerControl.addOverlay(trackerstationslayer, "Trackers", "Flight:  " + flightids[key].flightid);
            layerControl.addOverlay(predictedpathlayer, "Flight Prediction", "Flight:  " + flightids[key].flightid);
            layerControl.addOverlay(landingpredictionlayer, "Landing Predictions", "Flight:  " + flightids[key].flightid);
         }


        // add a scale widget in the lower left hand corner for miles / kilometers.
        var scale = L.control.scale({position: 'bottomright', maxWidth: 200}).addTo(map);

    }


    /************
     * startup
     *
     * This function performs some startup actions and calls "initialize", the primary function for starting the map stuff
    *************/
    function startup() {
        $.get("getposition.php", function(data) { 
            //document.getElementById("error").innerHTML = "followfeatureid:  " + followfeatureid + "   mycallsign:  " + mycallsign + " LBP:  " + lookbackperiod;
            lastposition = JSON.parse(data);
            initialize();

            document.getElementById("lookbackperiod").value = lookbackperiod;
            document.getElementById("iconsize").value = iconsize;
/*            if (plottracks == 'on')
                $("#plottracks").prop('checked', true);
            else
                $("#plottracks").prop('checked', false);
*/

            buildGauges();
            buildCharts();
            createTheListener();
            getProcessStatus();

            var flight;
            var allHtml = "<input type=\"radio\" id=\"allpackets\" name=\"flightLivePacketStream\" value=\"allpackets\" checked > All packets (< 3hrs) &nbsp; &nbsp;";
            var livePacketStreamHTML = "<form>" + allHtml;
            var i = 0;
            for (flight in flightids) {
                var pos_a = "#" + flightids[flight].flightid + "_positionpacketlistlink";
                var pos_l = "#" + flightids[flight].flightid + "_positionpacketlistsign";
                var pos_e = "#" + flightids[flight].flightid + "_positionpacketlist";

                var stat_a = "#" + flightids[flight].flightid + "_statuspacketlistlink";
                var stat_l = "#" + flightids[flight].flightid + "_statuspacketlistsign";
                var stat_e = "#" + flightids[flight].flightid + "_statuspacketlist";

                var inst_a = "#" + flightids[flight].flightid + "_instrumentpanellink";
                var inst_l = "#" + flightids[flight].flightid + "_instrumentpanelsign";
                var inst_e = "#" + flightids[flight].flightid + "_instrumentpanel";

                var alt_a = "#" + flightids[flight].flightid + "_altitudechartlink";
                var alt_l = "#" + flightids[flight].flightid + "_altitudechartsign";
                var alt_e = "#" + flightids[flight].flightid + "_altitudechart";

                var vert_a = "#" + flightids[flight].flightid + "_verticalchartlink";
                var vert_l = "#" + flightids[flight].flightid + "_verticalchartsign";
                var vert_e = "#" + flightids[flight].flightid + "_verticalchart";

                var rel_a = "#" + flightids[flight].flightid + "_relativepositionlink";
                var rel_l = "#" + flightids[flight].flightid + "_relativepositionsign";
                var rel_e = "#" + flightids[flight].flightid + "_relativeposition";


                $(pos_a).click({element: pos_e, link: pos_l }, toggle);
                $(stat_a).click({element: stat_e, link: stat_l }, toggle);
                $(inst_a).click({element: inst_e, link: inst_l }, toggle);
                $(alt_a).click({element: alt_e, link: alt_l }, toggle);
                $(vert_a).click({element: vert_e, link: vert_l }, toggle);
                //$(va_a).click({element: va_e, link: va_l }, toggle);
                $(rel_a).click({element: rel_e, link: rel_l }, toggle);

                // We use this to determine when the last packet came in for a given flight.
                $("#" + flightids[flight].flightid + "_sidebar").data("lastpacket", new Date("1970-01-01T00:00:00"));

                // Build the live packet stream HTML for flight selection
                //livePacketStreamHTML = livePacketStreamHTML + "<input type=\"radio\" id=\"flightLivePacketStream-" + flightids[flight].flightid + "\" name=\"flightLivePacketStream\"  value=\"" + flightids[flight].flightid + "\" " + (i == 0 ? "checked" : "") + " > " + flightids[flight].flightid + "&nbsp; &nbsp;";
                livePacketStreamHTML = livePacketStreamHTML + "<input type=\"radio\" id=\"flightLivePacketStream-" + flightids[flight].flightid + "\" name=\"flightLivePacketStream\"  value=\"" + flightids[flight].flightid + "\" > " + flightids[flight].flightid + "&nbsp; &nbsp;";
                //if (i == 0)
                //    currentflight = flightids[flight].flightid;
                i += 1;
            }
            var liveA_a = "#livePacketFlightSelectionLink";
            var liveA_l = "#livePacketFlightSelectionSign";
            var liveA_e = "#livePacketFlightSelection";

            var liveB_a = "#livePacketSearchLink";
            var liveB_l = "#livePacketSearchSign";
            var liveB_e = "#livePacketSearch";
            $(liveA_a).click({element: liveA_e, link: liveA_l }, toggle);
            $(liveB_a).click({element: liveB_e, link: liveB_l }, toggle);
 
            // Build the Live Packet Stream tab
            livePacketStreamHTML = livePacketStreamHTML + "</form>"; 
            document.getElementById("flightsLivePacketStream").innerHTML = livePacketStreamHTML;
 
            var e = document.getElementById('searchfield');
            e.oninput = updateLivePacketStream;
            e.onpropertychange = e.oninput;

            var e = document.getElementById('searchfield2');
            e.oninput = updateLivePacketStream;
            e.onpropertychange = e.oninput;

            var e = document.getElementById('operation');
            e.oninput = updateLivePacketStream;
            e.onpropertychange = e.oninput;


            // set onclick for the Live Packet Stream flight selection radio buttons
            for (flight in flightids) {
                $("#flightLivePacketStream-" + flightids[flight].flightid).on('click change', function(e) {
                    currentflight = selectedflight();
                    getLivePackets();
                });
            }

            $("#allpackets").on('click change', function(e) {
                currentflight = selectedflight();
                getLivePackets();
            });

            // set onclick for the start/stop buttons on the Live Packet Stream tab
            $("#livepacketstart").on('click change', function(e) {
                livePacketStreamState = 1;
                document.getElementById("livePacketStreamState").innerHTML = "<mark style=\"background-color: lightgreen;\">on</mark>";
                getLivePackets();
            });

            $("#livepacketstop").on('click change', function(e) {
                livePacketStreamState = 0;
                document.getElementById("livePacketStreamState").innerHTML = "<mark style=\"background-color: red;\">off</mark>";
                clearLivePacketFilters();
            });


            // Setup the Live Packet Stream event and handler 
            updateLivePacketStreamEvent= new CustomEvent("updateLivePacketStreamEvent");
            document.addEventListener("updateLivePacketStreamEvent", displayLivePackets, false);

            currentflight = "allpackets";
            getLivePackets();

            // add timer to update all charts, data, etc..
            setInterval(updateAllItems, 5000);

            // build the Trackers table
            getTrackers();

        });
    }


    /************
     * toggle
     *
     * This function will toggle the visiblity of elements.
    *************/
    function toggle(event) {
        var ele = $(event.data.element);
        var signEle = $(event.data.link);
        ele.slideToggle('fast', function() {
            if (ele.is(':visible')) {
                signEle.text('-');
                //document.getElementById("error-JEFF-275").innerHTML = "toggling...minus:  " + divId + ", " + switchTag;
             }
             else  {
                signEle.text('+');
                //document.getElementById("error-JEFF-275").innerHTML = "toggling...plus:  " + divId + ", " + switchTag;
             }
        });
    }

    /************
     * buildCharts
     *
     * This function creates the charts for the individual flight tabs.
    *************/
    function buildCharts () {
        var flight;
        var achart;
        var vchart;

        // build empty charts for each active flight
        var i = 0;
        for (flight in flightids) {
            var data = {};
            var cols = {};
            var altElement = "#" + flightids[flight].flightid + "_altitudechart";
            var vertElement = "#" + flightids[flight].flightid + "_verticalchart";
            
            achart = c3.generate({
                bindto: altElement,
                size: { width: 360, height: 250 },
                data: { empty : { label: { text: "No Data Available" } }, type: 'area', json: data, xs: cols, xFormat: '%H:%M:%S'  },
                axis: { x: { label: { text: 'Time', position: 'outer-center' }, type: 'timeseries', tick: { count: 6, format: '%H:%M:%S' }  }, y: { label: { text: 'Altitude (ft)', position: 'outer-middle' } } },
                //grid: { x: { show: true }, y: { show: true, lines: [{ value: lastposition.properties.altitude, class: 'groundlevel', text: 'Ground Level'}] } }
                grid: { x: { show: true }, y: { show: true } },
                line: { connectNull: true }
            });

            vchart = c3.generate({
                bindto: vertElement,
                size: { width: 360, height: 250 },
                data: { empty : { label: { text: "No Data Available" } }, type: 'area', json: data, xs: cols, xFormat: '%H:%M:%S'  },
                axis: { x: { label: { text: 'Time', position: 'outer-center' }, type: 'timeseries', tick: { count: 6, format: '%H:%M:%S' }  }, y: { label: { text: 'Vertical Rate (ft/min)', position: 'outer-middle' } } },
                //grid: { x: { show: true }, y: { show: true, lines: [{ value: lastposition.properties.altitude, class: 'groundlevel', text: 'Ground Level'}] } }
                grid: { x: { show: true }, y: { show: true } },
                line: { connectNull: true }
            });


            $(altElement).data('altitudeChart', achart);
            $(vertElement).data('verticalChart', vchart);
        }

        // Now query the backend database for chart data for all active flights, and load that data into the pre-built charts...
        $.get("getaltitudechartdata.php?lookbackperiod=" + lookbackperiod, function(data) {
            var thejsondata;
            var i = 0;
            var thekeys;

            if (data.length > 0) {
                thejsondata = JSON.parse(data);
                thekeys = Object.keys(thejsondata);


                //document.getElementById("error").innerHTML = JSON.stringify(thekeys);
                for (i = 0; i < thekeys.length; i++) {
                        var flight = thejsondata[i];
                    var jsondata = flight.chartdata;
                    var k = 0;
                    var chartkeys = Object.keys(jsondata);
                    var cols = {};
                    var thisflightid = flight.flightid;
                    var element = "#" + thisflightid + "_altitudechart";

                    for (k = 0; k < chartkeys.length; k++) {  
    
                        if (! chartkeys[k].startsWith("tm-")) {
                            cols[chartkeys[k]] = "tm-" + chartkeys[k];
                        }
                    }

                    //document.getElementById("error2").innerHTML = "thisflightid:  " + thisflightid + "<br>" + JSON.stringify(cols);
                    
                    // Load data into each Altitude chart
                    var achart = $(element).data('altitudeChart');
                    achart.load({ json: jsondata, xs: cols }); 
                 }
             }
        });


        // Now query the backend database for chart data for all active flights, and load that data into the pre-built charts...
        $.get("getvertratechartdata.php?lookbackperiod=" + lookbackperiod, function(data) {
            var thejsondata;
            var i = 0;
            var thekeys;

            if (data.length > 0) {
                thejsondata = JSON.parse(data);
                thekeys = Object.keys(thejsondata);


                //document.getElementById("error").innerHTML = JSON.stringify(thekeys);
                for (i = 0; i < thekeys.length; i++) {
                        var flight = thejsondata[i];
                    var jsondata = flight.chartdata;
                    var k = 0;
                    var chartkeys = Object.keys(jsondata);
                    var cols = {};
                    var thisflightid = flight.flightid;
                    var element = "#" + thisflightid + "_verticalchart";

                    for (k = 0; k < chartkeys.length; k++) {  
    
                        if (! chartkeys[k].startsWith("tm-")) {
                            cols[chartkeys[k]] = "tm-" + chartkeys[k];
                        }
                    }

                    //document.getElementById("error2").innerHTML = "thisflightid:  " + thisflightid + "<br>" + JSON.stringify(cols);
                    
                    // Load data into each Altitude chart
                    var vchart = $(element).data('verticalChart');
                    vchart.load({ json: jsondata, xs: cols }); 
                 }
             }
        });

    }

    /************
     * buildGauges
     *
     * This function creates the gauges/instrumentation for the individual flight tabs.
    *************/
    function buildGauges () {
	var altimeter;
	var variometer;
	var heading;
	var airspeed;
        var relativebearing;
        var relativeangle;
        var flights = [];
        var flight;

	for (flight in flightids) {
	    var altitudeInstrument = "#" + flightids[flight].flightid + "_altimeter";
	    var verticalRateInstrument = "#" + flightids[flight].flightid + "_variometer";
	    var balloonHeadingInstrument = "#" + flightids[flight].flightid + "_heading";
	    var speedInstrument = "#" + flightids[flight].flightid + "_airspeed";
	    var relativeBearingInstrument = "#" + flightids[flight].flightid + "_relativebearing";
            var relativeElevationInstrument = "#" + flightids[flight].flightid + "_relativeelevationangle";

	    var altitudeValue = "#" + flightids[flight].flightid + "_altitudevalue";
	    var verticalRateValue = "#" + flightids[flight].flightid + "_verticalratevalue";
	    var balloonHeadingValue = "#" + flightids[flight].flightid + "_headingvalue";
	    var speedValue = "#" + flightids[flight].flightid + "_speedvalue";
	    var relativeBearingValue = "#" + flightids[flight].flightid + "_relativebearingvalue";
            var relativeElevationValue = "#" + flightids[flight].flightid + "_relativeelevationanglevalue";


            altimeter = $.flightIndicator(altitudeInstrument, 'altimeter', { showBox: true, size: 180 });
            variometer = $.flightIndicator(verticalRateInstrument, 'variometer', { showBox: true, size: 180 });
            heading = $.flightIndicator(balloonHeadingInstrument, 'heading', { showBox: true, size: 180 });
            airspeed = $.flightIndicator(speedInstrument, 'airspeed', { showBox: true, size: 180 });
            relativebearing = $.flightIndicator(relativeBearingInstrument, 'relativeHeading', { showBox: true, size: 180 });
            relativeangle = $.flightIndicator(relativeElevationInstrument, 'elevationAngle', { showBox: true, size: 180 });
 
            $(altitudeValue).data('altimeter', altimeter);
            $(verticalRateValue).data('variometer', variometer);
            $(balloonHeadingValue).data('heading', heading);
            $(speedValue).data('airspeed', airspeed);
            $(relativeBearingValue).data('relativebearing', relativebearing);
            $(relativeElevationValue).data('relativeangle', relativeangle);
	}
    }


    /************
     * createTheListener
     *
     * This function creates the primary Listener handler for when to update the gauges and other instruments/indicators
    *************/
    function createTheListener() {

        var theListener = document.addEventListener("UpdateFlightGauges", function(event) {
            var flightid = event.detail.properties.flightid;
            var incomingTime = new Date(event.detail.properties.time.replace(/ /g, "T"));
            var lastTime = $("#" + flightid + "_sidebar").data("lastpacket");

            //document.getElementById("error2").innerHTML = JSON.stringify(event.detail.properties);
            // if incoming packet is a later time and it's been at least 4 seconds since the last update...then update the gauges
            if (incomingTime > lastTime && (incomingTime.getTime() - lastTime.getTime() / 1000) > 4) {

                // Bring the "top" beacons to the top of the z-order for Leaflet feature groups
                /*if (event.detail.properties.order)
                    if (event.detail.properties.order == "top") {
                        var k;
                        for (k = 0; k < activeflights.length; k++) {
                            if (activeflights[k].callsign == event.detail.properties.callsign) {
                                var group = activeflights[k].layergroup;
                                //group.bringToFront();
                                group.setZIndex(event.detail.properties.altitude/1000);
                                //document.getElementById("error").innerHTML = "bring to front: " + event.detail.properties.callsign;
                                break;
                            }
                        }
                    }
*/


                // The telemetry
                var thealtitude = Math.round((event.detail.properties.altitude * 10) / 10);
                var theheading = Math.round((event.detail.properties.bearing * 10) / 10);
                var thespeed = Math.round((event.detail.properties.speed * 10) / 10);
                var thevertrate = Math.round((event.detail.properties.verticalrate * 10) / 10);

                // The element names for displaying the telemetry
                var altitudeValue = "#" + flightid + "_altitudevalue";
        	var verticalRateValue = "#" + flightid + "_verticalratevalue";
                var balloonHeadingValue = "#" + flightid + "_headingvalue";
                var speedValue = "#" + flightid + "_speedvalue";

                // Update the gauges themselves
                if (thealtitude > 0 && thevertrate/1000 < 20000 && thevertrate/1000 > -20000) {
                    $(altitudeValue).data("altimeter").setAltitude(thealtitude);
                    $(verticalRateValue).data("variometer").setVario(thevertrate/1000);
                }
                $(balloonHeadingValue).data("heading").setHeading(theheading);
                $(speedValue).data("airspeed").setAirSpeed(thespeed);

                // Update the text displays
                if (thealtitude > 0 && thevertrate/1000 < 20000 && thevertrate/1000 > -20000) {
                    $(altitudeValue).text(thealtitude.toLocaleString());
                    $(verticalRateValue).text(thevertrate.toLocaleString());
                }
                $(balloonHeadingValue).text(theheading);
                $(speedValue).text(thespeed);

                // Update the last position packets table
                $.get("getpositionpackets.php?flightid=" + flightid + "&lookbackperiod=" + lookbackperiod, function(data) {
                    var jsonData = JSON.parse(data);
                    var k = 0;
        
                    
                    while (k < 5 && jsonData[k]) {
                        var item = jsonData[k];
               
                        $("#" + item.flightid + "_lasttime_" + k).text(item.time.split(" ")[1]);
                        $("#" + item.flightid + "_lastcallsign_" + k).text(item.callsign);
                        $("#" + item.flightid + "_lastspeed_" + k).text(Math.round(item.speed * 10 / 10) + " mph");
                        $("#" + item.flightid + "_lastvertrate_" + k).text(Math.round(item.verticalrate * 10 / 10).toLocaleString() + " ft/min");
                        $("#" + item.flightid  + "_lastaltitude_" + k).text(Math.round(item.altitude * 10 / 10).toLocaleString() + " ft");
                        k += 1;
                    }
                });
                
                // update the time for the last packet
                $("#" + flightid + "_sidebar").data("lastpacket", incomingTime);
            }
        });
    }



    /************
     * UpdateAllItems
     *
     * This function updates other parts of the instrumentation, charts, and tables.
     * This will update every chart/graph/table globally.
    *************/
    function updateAllItems() {
        // Now query the backend database for chart data for all active flights, and load that data into the pre-built charts...
        $.get("getaltitudechartdata.php?lookbackperiod=" + lookbackperiod, function(data) {
            var thejsondata;
            var i = 0;
            var thekeys;

            if (data.length > 0) {
                thejsondata = JSON.parse(data);
                thekeys = Object.keys(thejsondata);


                //document.getElementById("error").innerHTML = JSON.stringify(thekeys);
                for (i = 0; i < thekeys.length; i++) {
                        var flight = thejsondata[i];
                    var jsondata = flight.chartdata;
                    var k = 0;
                    var chartkeys = Object.keys(jsondata);
                    var cols = {};
                    var thisflightid = flight.flightid;
                    var element = "#" + thisflightid + "_altitudechart";

                    for (k = 0; k < chartkeys.length; k++) {  

                        if (! chartkeys[k].startsWith("tm-")) {
                            cols[chartkeys[k]] = "tm-" + chartkeys[k];
                        }
                    }

                    //document.getElementById("error2").innerHTML = "thisflightid:  " + thisflightid + "<br>" + JSON.stringify(cols);
                    
                    // Load data into each Altitude chart
                    var achart = $(element).data('altitudeChart');
                    achart.load({ json: jsondata, xs: cols }); 
                 }
             }
        });


        // Now query the backend database for chart data for all active flights, and load that data into the pre-built charts...
        $.get("getvertratechartdata.php?lookbackperiod=" + lookbackperiod, function(data) {
            var thejsondata;
            var i = 0;
            var thekeys;

            if (data.length > 0) {
                thejsondata = JSON.parse(data);
                thekeys = Object.keys(thejsondata);


                //document.getElementById("error").innerHTML = JSON.stringify(thekeys);
                for (i = 0; i < thekeys.length; i++) {
                        var flight = thejsondata[i];
                    var jsondata = flight.chartdata;
                    var k = 0;
                    var chartkeys = Object.keys(jsondata);
                    var cols = {};
                    var thisflightid = flight.flightid;
                    var element = "#" + thisflightid + "_verticalchart";

                    for (k = 0; k < chartkeys.length; k++) {  
    
                        if (! chartkeys[k].startsWith("tm-")) {
                            cols[chartkeys[k]] = "tm-" + chartkeys[k];
                        }
                    }

                    //document.getElementById("error2").innerHTML = "thisflightid:  " + thisflightid + "<br>" + JSON.stringify(cols);

                    // Load data into each Altitude chart
                    var vchart = $(element).data('verticalChart');
                    vchart.load({ json: jsondata, xs: cols }); 
                 }
             }
        });


        // Calculate our relative position to this flight's latest position packet
        $.get("getrelativeposition.php?lookbackperiod=" + lookbackperiod, function(data) {
            var thejsondata;
            var i = 0;
            var thekeys;

            // json should look like this:  { "flightid" : "EOSS-123", "myheading" : "123", "range" : "123", "angle" : "123.123", "bearing" : "123.123" }

            if (data.length > 0) {
                thejsondata = JSON.parse(data);
                thekeys = Object.keys(thejsondata);

                //document.getElementById("error").innerHTML = JSON.stringify(thekeys);
                for (i = 0; i < thekeys.length; i++) {
                    var flight = thejsondata[i];

                    // These are the values that are stuck in the table
                    var delement = "#" + flight.flightid + "_relativepositiondistance";
                    var celement = "#" + flight.flightid + "_relativeballooncoords";

                    // These are the dials
                    var eelement = "#" + flight.flightid + "_relativeelevationangle";
                    var evelement = "#" + flight.flightid + "_relativeelevationanglevalue";
                    var hvelement = "#" + flight.flightid + "_relativebearingvalue";
                    var mhvelement = "#" + flight.flightid + "_myheadingvalue";

                    var relativeBearing = flight.bearing - flight.myheading;
                    if (relativeBearing < 0)
                        relativeBearing = 360 + relativeBearing;

                    $(hvelement).data("relativebearing").setRelativeHeading(flight.myheading, flight.bearing);
                    $(evelement).data("relativeangle").setElevationAngle(flight.angle);
                    $(delement).text(flight.distance + " mi");
                    $(celement).text(flight.latitude + ", " + flight.longitude);
                    $(evelement).text(flight.angle);
                    $(hvelement).text(relativeBearing);
                    $(mhvelement).text(flight.myheading);
                 }
             }
        });

        // Update the last status packets table 
        for (flightid in flightids) {
            $.get("getstatuspackets.php?flightid=" + flightids[flightid].flightid + "&lookbackperiod=" + lookbackperiod, function(data) {
                var jsonData = JSON.parse(data);
                var k = 0;
            
                while (k < 5 && jsonData[k]) {
                    var item = jsonData[k];
          
                    $("#" + item.flightid + "_statustime_" + k).text(item.time.split(" ")[1]);
                    $("#" + item.flightid + "_statuscallsign_" + k).text(item.callsign);
                    $("#" + item.flightid + "_statuspacket_" + k).text(item.packet);
                    k += 1;
                }
            });
        }


        // Update process status
        getProcessStatus();
        
        // Update the live packet stream tab
        getLivePackets();

    }


    $(document).ready(startup);

</script>
    <!-- this is for the sidebar html -->
    <div id="sidebar" class="sidebar collapsed">
        <!-- Nav tabs -->
        <div class="sidebar-tabs">
            <ul role="tablist">
                <li><a href="#home" role="tab"><img src="/images/graphics/home.png" width="30" height="30"></a></li>
<?php
    if ($numflights > 0) {
        foreach ($flightlist as $row){
            list($prefix, $suffix) = explode('-', $row['flightid']);
            printf("<li><div style=\"text-align: center; vertical-align:  middle;\"><strong><a href=\"#%s_sidebar\" role=\"tab\" class=\"flightlink\">%s</a></strong></div></li>", $row['flightid'], $suffix);
        }
    }
?>
            </ul>

            <ul role="tablist">
                <li><a href="#settings" role="tab"><img src="/images/graphics/gear.png" width="30" height="30"></a></li>
            </ul>
        </div>

        <!-- Tab panes -->
        <div class="sidebar-content">
            <div class="sidebar-pane" id="home">
                <h1 class="sidebar-header">Home<span class="sidebar-close"><img src="/images/graphics/leftcaret.png" width="30" height="30"></span> </h1>
                <p class="logo" style="margin-top:  30px; margin-bottom: 0px;"><?php if (isset($logo)) printf("%s", $logo); else printf("No Logo"); ?><br>
                </p>
                <p style="margin-top: 0px; font-size: 1.1em; color:  #0052cc; font-style:  italic; text-shadow: 5px 5px 10px gray; margin-bottom:  30px;">Tracking High Altitude Balloons</p>
                <p class="lorem">Welcome to the Map utilty for the high altitude balloon tracker application.  From these screens one can monitor positions of APRS objects and their repsective paths.</p>
                <p class="section-header">System: &nbsp;  <?php echo $_SERVER["HTTP_HOST"]; ?>
                    <br>System Status: <span id="systemstatus"></span></p>
                <table class="packetlist">
                    <tr><td class="packetlistheader">Process</td><td class="packetlistheader">Status</td></tr>
                    <tr><td class="packetlist">direwolf</td><td class="packetlist"><span id="direwolf-status"></span></td></tr>
                    <tr><td class="packetlist">aprsc</td><td class="packetlist"><span id="aprsc-status"></span></td></tr>
                    <tr><td class="packetlist">gpsd</td><td class="packetlist"><span id="gpsd-status"></span></td></tr>
                    <tr><td class="packetlist">backend daemon</td><td class="packetlist"><span id="habtracker-d-status"></span></td></tr>
                </table>
                <div><span id="myerror"></span></div>
            </div>

            <div class="sidebar-pane" id="profile">
                <h1 class="sidebar-header">Trackers<span class="sidebar-close"><img src="/images/graphics/leftcaret.png" width="30" height="30"></span></h1>
                <p class="lorem">This tab shows the list of active trackers for the current mission.</p>
                <p class="section-header">Tracker List</p>

                <div id="trackers">
                </div>
                <div id="newtrackererror"></div>

            </div>
            <div class="sidebar-pane" id="messages">
                <h1 class="sidebar-header">Live Packet Stream<span class="sidebar-close"><img src="/images/graphics/leftcaret.png" width="30" height="30"></span></h1>
                <p class="section-header">Live Packet Stream: &nbsp; <span id="livePacketStreamState"><mark style="background-color: red;">off</mark></span></p>
                <p class="lorem">This tab will display all APRS packets received on today's date for a given flight.  
                    Packets are displayed in reverse chronological order with the latest packets on top, oldest on bottom.</p>

                <p class="section-header"><a href="#" class="section-link" id="livePacketFlightSelectionLink">(<span style="color: red;" id="livePacketFlightSelectionSign">-</span>) Select flight</a>:</p>
                <div id="livePacketFlightSelection">
                    <p class="lorem">To start the packet stream, select a flight, then click start.  Once running, the packet display will be automatically updated every 5 seconds.</p>
                    <p><span id="flightsLivePacketStream"></span></p>
                    <p class="section-header"><button name="livepacketstart" id="livepacketstart" >Start</button><button name="livepacketstop" id="livepacketstop">Stop</button></p>
                </div>
 
                <p class="section-header"><a href="#" class="section-link" id="livePacketSearchLink">(<span style="color: red;" id="livePacketSearchSign">-</span>) Search</a>:</p>
                <div id="livePacketSearch">
                    <p class="lorem">Enter search characters to filter the displayed packets.  All searches are case insensitive, so "AAA" is equivalent to "aaa".</p>
                    <p>
                    <input type="text" size="16" maxlength="128" name="searchfield" id="searchfield" autocomplete="off" autocapitalize="off" spellcheck="false" autocorrect="off">
                    <select id="operation">
                        <option value="and" selected="selected">And</option>
                        <option value="or">Or</option>
                        <option value="not">Not</option>
                    </select>
                    <input type="text" size="16" maxlength="128" name="searchfield2" id="searchfield2" autocomplete="off" autocapitalize="off" spellcheck="false" autocorrect="off" >
                    </p>
                    <p><button onclick="clearLivePacketFilters();">Clear</button></p>
                </div>
                <p class="section-header">Packets: <mark><span id="packetcount">0</span></mark></p>
                <div class="packetdata"><p class="packetdata"><span id="packetdata"></span></p></div>
            </div>

            <div class="sidebar-pane" id="settings">
                <h1 class="sidebar-header">Settings<span class="sidebar-close"><img src="/images/graphics/leftcaret.png" width="30" height="30"></span></h1>
                <p class="section-header">Preferences:</p>
                <form id="userpreferences" action="preferences.php" name="userpreferences">
                <table cellpadding=5 cellspacing=0 border=0 class="preferencestable">
<!--                    <tr><td class="section" colspan=2><strong>Preferences:</strong><td></tr> -->
<!--                    <tr><td style="vertical-align:  top;">My Callsign:<p class="lorem">This is your callsign.</p></td><td style="vertical-align:  top; white-space: nowrap;"><input type="text" name="mycallsign" style="text-transform: uppercase" placeholder="CALL-xx" id="mycallsign" pattern="[a-zA-Z]{1,2}[0-9]{1}[a-zA-Z]{1,3}-[0-9]{1,2}" size="10" maxlength="9" form="userpreferences" autocomplete="off" autocapitalize="off" spellcheck="false" autocorrect="off"></td></tr>
-->
		    <tr><td style="vertical-align:  top;">Lookback Period:<br><p class="lorem">How far back in time the map will look, when plotting APRS objects and paths.</p></td><td style="vertical-align:  top; white-space: nowrap;"><input type="text" name="lookbackperiod" id="lookbackperiod" size="4" pattern="[0-9]{1,3}" placeholder="nnn"  form="userpreferences"> minutes</td></tr>
                    <tr><td style="vertical-align:  top;">Icon Size:<br><p class="lorem">Changes how large the icons are for APRS objects on the map.</p></td><td style="vertical-align:  top; white-space: nowrap;"><input type="text" name="iconsize" id="iconsize" size="3" maxlength="2" form="userpreferences" pattern="[0-9]{2}" placeholder="nn"> pixels</td></tr>
                    <!-- <tr><td style="vertical-align:  top;">Display landing prediction tracks?<br><p class="lorem">Should tracks be displayed for predicted landing locations.</p></td><td style="vertical-align:  top;"><input type="checkbox" name="plotlandingtracks" id="plotlandingtracks" checked form="userpreferences"></td></tr> -->
                    <tr><td colspan=2><input type="submit" value="Submit" form="userpreferences" style="font-size:  1.2em;"></td></tr>
                </table>
                </p>
                <div id="error"></div>
                <div id="error2"></div>
                <div id="error3"></div>
                <div style="position: absolute; bottom: 10px; width: 360px;">
                    <p class="section-header">System Version: <?php if (isset($version)) printf ("%.1f", $version); ?></p>
                    <p class="lorem">This is the version of the HAB Tracker application.</p>
                </div>
            </div>

<?php

 if ($numflights > 0) {
    foreach ($flightlist as $row) {
        printf ("<div class=\"sidebar-pane\" id=\"%s\">", $row['flightid'] . "_sidebar");
        printf ("<h1 class=\"sidebar-header\">Flight %s<span class=\"sidebar-close\"><img src=\"/images/graphics/leftcaret.png\" width=\"30\" height=\"30\"></span></h1>", $row['flightid']);

        // Instrument panel
        printf ("<p class=\"section-header\"><a href=\"#instruments\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">-</span>) Instrument Panel</a>:</p>", $row['flightid'] . "_instrumentpanellink", $row['flightid'] . "_instrumentpanelsign");
        printf ("<div id=\"%s\">", $row['flightid'] . "_instrumentpanel");
        printf ("<div class=\"instrumentpanel\">");
        printf ("   <div class=\"column\">");
        printf ("       <div class=\"rowtop\">");
        printf ("           <center><div class=\"readouttop\"><p class=\"instrumentvalue\"><span id=\"%s\"></span> ft</p></div></center>", $row['flightid'] . "_altitudevalue");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_altimeter");
        printf ("       </div>");
        printf ("       <div class=\"rowbottom\">");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_heading");
        printf ("           <center><div class=\"readoutbottom\"><p class=\"instrumentvalue\"><span id=\"%s\"></span> deg</p></div></center>", $row['flightid'] . "_headingvalue");
        printf ("       </div>");
        printf ("   </div>");
        printf ("   <div class=\"column\">");
        printf ("       <div class=\"rowtop\">");
        printf ("           <center><div class=\"readouttop\"><p class=\"instrumentvalue\"><span id=\"%s\"></span> ft/min</p></div></center>", $row['flightid'] . "_verticalratevalue");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_variometer");
        printf ("       </div>");
        printf ("       <div class=\"rowbottom\">");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_airspeed");
        printf ("           <center><div class=\"readoutbottom\"><p class=\"instrumentvalue\"><span id=\"%s\"></span> mph</p></div></center>", $row['flightid'] . "_speedvalue");
        printf ("       </div>");
        printf ("   </div>");
        printf ("</div>");
        printf ("</div>");

        // Relative position section
        printf ("<p class=\"section-header\"><a href=\"#relative\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">+</span>) Relative Position</a>: </p>", $row['flightid'] . "_relativepositionlink", $row['flightid'] . "_relativepositionsign");
        printf ("<div id=\"%s\" style=\"display: none;\">", $row['flightid'] . "_relativeposition");
        printf ("<div class=\"lowerinstrumentpanel\">");
        printf ("   <div class=\"column\" style=\"height: 235px;\">");
        printf ("       <div class=\"rowtop\" style=\"padding-top: 3px;\">");
        printf ("           <center><div class=\"readouttop\"><p class=\"instrumentvalue\">&nbsp;</p></div></center>");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_relativeelevationangle");
        printf ("           <center><div class=\"readoutbottom\"><p class=\"instrumentvalue\">Angle: <span id=\"%s\"></span> deg</p></div></center>", $row['flightid'] . "_relativeelevationanglevalue");
        printf ("       </div>");
        printf ("   </div>");
        printf ("   <div class=\"column\" style=\"height: 235px;\">");
        printf ("       <div class=\"rowtop\" style=\"padding-top:  3px;\">");
        printf ("           <center><div class=\"readouttop\"><p class=\"instrumentvalue\">Hdng: <span id=\"%s\"></span> deg</p></div></center>", $row['flightid'] . "_myheadingvalue");
        printf ("           <center><span id=\"%s\"></span></center>", $row['flightid'] . "_relativebearing");
        printf ("           <center><div class=\"readoutbottom\"><p class=\"instrumentvalue\">Brng: <span id=\"%s\"></span> deg</p></div></center>", $row['flightid'] . "_relativebearingvalue");
        printf ("       </div>");
        printf ("   </div>");
//        printf ("   <center><div class=\"readoutbottom\" style=\"width: 360px;\"><p class=\"instrumentvalue\" style=\"width:  360px;\">Distance: <span id=\"%s\"></span> &nbsp; B. Coords: <span id=\"%s\"</span></p></div></center>", $row['flightid'] . "_relativepositiondistance", $row['flightid'] . "_relativeballooncoords");
        printf ("</div>");
        printf ("    <table class=\"packetlistpanel\" style=\"width:  360px;\">");
        printf ("        <tr><td class=\"packetlistheaderpanel\">Distance To Balloon</td>");
        printf ("            <td class=\"packetlistheaderpanel\">Balloon Coords</td>");
        printf ("        <tr><td class=\"packetlistpanel\"><mark><span id=\"%s\"</span></mark></td>", $row['flightid'] . "_relativepositiondistance");
        printf ("            <td class=\"packetlistpanel\"><mark><span id=\"%s\"</span></mark></td>", $row['flightid'] . "_relativeballooncoords");
        printf ("        </tr>");
        printf ("    </table>");
        printf ("</div>");

        // Lastest position packets section
        printf ("<p class=\"section-header\"><a href=\"#positions\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">+</span>) Most Recent Position Packets</a>:</p>", $row['flightid'] . "_positionpacketlistlink", $row['flightid'] . "_positionpacketlistsign");
        printf ("<div id=\"%s\" style=\"display: none;\">", $row['flightid'] . "_positionpacketlist");
        printf ("    <table class=\"packetlist\">");
        printf ("        <tr><td class=\"packetlistheader\">Time</td>");
        printf ("            <td class=\"packetlistheader\">Callsign</td>");
        printf ("            <td class=\"packetlistheader\">Speed</td>");
        printf ("            <td class=\"packetlistheaderright\">V. Rate</td>");
        printf ("            <td class=\"packetlistheaderright\">Altitude</td></tr>");
        for ($i = 0; $i < 5; $i++) {
            printf ("        <tr><td class=\"packetlist\"><span id=\"%s_lasttime_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlist\"><span id=\"%s_lastcallsign_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlist\"><span id=\"%s_lastspeed_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlistright\"><span id=\"%s_lastvertrate_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlistright\"><span id=\"%s_lastaltitude_%d\"</span></td>", $row['flightid'], $i);
            printf ("        </tr>");
        }
        printf ("    </table>");
        printf ("</div>");

        // Lastest status packets section
        printf ("<p class=\"section-header\"><a href=\"#status\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">+</span>) Most Recent Status Packets</a>:</p>", $row['flightid'] . "_statuspacketlistlink", $row['flightid'] . "_statuspacketlistsign");
        printf ("<div id=\"%s\" style=\"display: none;\">", $row['flightid'] . "_statuspacketlist");
        printf ("    <table class=\"packetlist\" style=\"width: 100%%; table-layout: auto;\">");
        printf ("        <tr><td class=\"packetlistheader\" style=\"width: 1%%;\">Time</td>");
        printf ("            <td class=\"packetlistheader\" style=\"width: 1%%;\">Callsign</td>");
        printf ("            <td class=\"packetlistheader\" style=\"width: 1%%;\">Packet</td>");
        for ($i = 0; $i < 5; $i++) {
            printf ("        <tr><td class=\"packetlist\" style=\"width: 1%%;\"><span id=\"%s_statustime_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlist\" style=\"width: 1%%;\"><span id=\"%s_statuscallsign_%d\"</span></td>", $row['flightid'], $i);
            printf ("            <td class=\"packetlist\" style=\"width: 100%%; white-space: normal;\"><span id=\"%s_statuspacket_%d\"</span></td>", $row['flightid'], $i);
            printf ("        </tr>");
        }
        printf ("    </table>");
        printf ("</div>");

        // Altitude Chart
        printf ("<p class=\"section-header\"><a href=\"#altitude\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">+</span>) Altitude Chart</a>:</p>", $row['flightid'] . "_altitudechartlink", $row['flightid'] . "_altitudechartsign");
        printf ("<div id=\"%s\" style=\"display: none;\">", $row['flightid'] . "_altitudechart");
        printf ("</div>");

        // Vertical Rate Chart
        printf ("<p class=\"section-header\"><a href=\"#vertical\" class=\"section-link\" id=\"%s\">(<span style=\"color: red;\" id=\"%s\">+</span>) Vertical Rate Chart</a>:</p>", $row['flightid'] . "_verticalchartlink", $row['flightid'] . "_verticalchartsign");
        printf ("<div id=\"%s\" style=\"display: none;\">", $row['flightid'] . "_verticalchart");
        printf ("</div>");

        // the error DIV
        printf ("<div style=\"float: left;\" id=\"error-%s\"></div>", $row['flightid']);
        printf ("</div>");
    }
 }
?>
        </div>
    </div>
    <div class="map" id="map"></div>
<?php
    //include $documentroot . '/common/footer.php';
    sql_close($link);
?>
</body>
</html>
