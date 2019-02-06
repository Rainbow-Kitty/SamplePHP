<?php
    /******************************************************************
    **  Garden Funds : Top / Bottom 10 Report
    **  
    **  May 5, 2003, created by:  Kevin Harrigan
    **  June 23, 2003, reversed order from high-low to low-high
    **  August 13, 2004, added color coding for virtually owned stocks
    **  June 05, 2017, changed interface to mysqli for php 7.x migration
    **
    **  Use cases:
    **  (1) Given a list of symbols, show 10 best/worst percentage gainers
    **
    **  Notes:
    **  '@' before file I/O functions suppresses warning messages
    ******************************************************************/
    // setup constants ************************************************
    define( 'DEBUG', 0 );        // if not zero, enable debug output

    // initialize variables for this page ******************************
    $title = 'Top / Bottom 10 Report';

    if ( DEBUG > 0 ) {
        if ( isset( $session_uid ) ) {
            echo __LINE__, ': session_uid = "', $session_uid, '"<br>';
        }
    }

    if ( !isset( $_SESSION ) ) {
        session_start();
    }
?>
<html>
<head>
    <title>
    <?php
        echo $title;
    ?>
    </title>
    <meta http-equiv='content-type' content='text/html; charset=ISO-8859-1'>
    <meta http-equiv='[remove brackets and bracketed text to resume refreshing every 5 minutes]refresh' content='300'> <!-- Refresh every 5 minutes -->
</head>

<body>

    <a href='PortfolioTools.php' style='font-size: 250%; color: #009900; font-weight: bold;'>Garden Funds</a>

    <span style='font-size: 150%; color: #009900; font-weight: bold;'>
        <?php
            echo ": $title";
        ?>
    </span>

<style type="text/css">
    table, th, td {
        border: 1px solid blue;
        border-spacing: 0;
    }

    tr:hover {
        background-color: #f5f5f5;
    }

    tr {
        text-align: right;
    }

    th {
        text-align: center;
    }
    
    body {
        background-color: #ffffcc;
    }
    
    a {
        color: #3333ff;
    }
    
    a:link {
        color: #0000ee;
    }
    
    a:visited {
        color: #666633;
    }
</style>

<?php
    // this code is part of the fire ring reporting
    require_once( 'HTMLHeader.inc' );

    // contains connection logic for mysql and some database abstraction functions
    require_once( 'gf.php' );
    if ( DEBUG > 1 ) {
        echo 'connected to database<br>';
    }

    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    //
    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    function own_this_security( $symbol ): bool
    {
        $query = 'SELECT 1 ' .
                 'FROM brokerage_taxable_trades btt JOIN brokerage_security_information bsi ' .
                   'ON btt.Security_Information_Id = bsi.Security_Information_Id ' .
                 'WHERE (btt.Buy_Transaction_Id = 0 OR btt.Sell_Transaction_Id = 0) ' .
                   "AND bsi.Security_Ticker = '$symbol'";
        $row = DbSingleRowFetch( $query );
        // if no avg vol present or less than 10,000 set volume ratio to zero
        if ( !$row ) {
            return false;
        }

        return true;
    }


    function virtually_own_this_security( $symbol ): bool
    {
        $query = 'SELECT 1 ' .
                 'FROM virtual_funds ' .
                 "WHERE Symbol = '$symbol'";
        $row = DbSingleRowFetch( $query );
        if ( !$row ) {
            return false;
        }

        return true;
    }


    // default SummaryURL to finance.yahoo.com
    $SummaryURL = 'http://finance.yahoo.com/q?s=%SYMBOL%&d=t';

    // if user has different default SummaryURL then use it
    if ( isset( $_SESSION, $_SESSION['User'] ) ) {
        $result = $mysqli->query( $query = 'SELECT aValue ' .
                                           'FROM user_configuration_data ' .
                                           "WHERE User = '" . $_SESSION['User'] . "' AND Section = 'ExternalLinks' AND aKey = 'SummaryURL'" );
        if ( $result ) {
            $row = $result->fetch_array();
            if ( $row && !empty( $row[0] ) ) {
                $SummaryURL = $row[0];
            }
        }
    }


    // create list of interesting ticker symbols
    $query = 'SELECT alist.* ' .
             'FROM finra_security_list fsl, ( ' .
                'SELECT es.Symbol, es.Symbol AS Ticker, es.Name ' .
                'FROM evaluating_securities es JOIN ( ' .
                   'SELECT Symbol ' .
                   'FROM securities s ' .
                   'WHERE Expiration_Date > NOW() ' .
                     'AND (Stop_Watching_Date IS NULL OR Stop_Watching_Date > CURRENT_DATE()) ' .
                     'AND Security_Type_Id NOT IN ( 5, 6, 7 ) ' .
                'UNION ' .
                   'SELECT DISTINCT bsi.Security_Ticker ' .
                   'FROM brokerage_taxable_trades btt JOIN brokerage_security_information bsi ON btt.Security_Information_Id = bsi.Security_Information_Id ' .
                   'WHERE btt.Buy_Transaction_Id = 0 OR btt.Sell_Transaction_Id = 0 ' .
                'UNION ' .
                   'SELECT DISTINCT vf.Symbol ' .
                   'FROM virtual_funds vf ' .
                'UNION ' .
                   'SELECT Symbol ' .
                   'FROM prices_today ' .
                   'WHERE Trade_Date >= CURRENT_DATE() ' .
                'UNION ' .
                   'SELECT DISTINCT Symbol ' .           // added symbol selection from daily_percentage_leaders 2016-02-20
                   'FROM daily_percentage_leaders ' .
                   'WHERE End_Of_Day > DATE_SUB( CURRENT_DATE(), INTERVAL 90 DAY ) ' .
                      "AND Status = 'Watch' " .
                   'ORDER BY 1 ) symbols ' .
                'WHERE symbols.Symbol = es.Symbol ) alist ' .
             'WHERE alist.Symbol = fsl.Symbol ' .
               'AND fsl.Updated_Date > DATE_SUB( CURRENT_DATE(), INTERVAL 7 DAY) ' .
             '';

    set_time_limit( 90 );
    
    if ( DEBUG > 0 ) {
        echo __LINE__, ": query [$query]<br>\n";
    }

    // Take above query and use it in quotes fetching code
    // results in $quotes array
    // result format compatible to old (defunct) yahoo quotes api
    require_once( 'QuotesFlashFromNasdaq.inc' );
//    require_once( 'QuotesInfoFromNasdaq.inc' );


    //
    // setup Percent array and Volume (as % of average volume) array
    foreach ( $quotes as $Key => $Value ) {
        // if data is missing for symbol, continue past it
        if ( !isset( $quotes[$Key]['Data'] ) ) {
            continue;
        }

        $read = $quotes[$Key]['Data'];
        // if no volume, skip this stock
        if ( 0 == $read[8] ) {          // allow type juggling
            continue;
        }

        // if price < $1, skip this stock
        if ( 1 > $read[1] ) {
            continue;
        }

        if ( is_numeric( $read[1] ) and is_numeric( $read[4] ) ) {
            if ( $read[1] == $read[4] ) {       // allow type juggling
                $Percent[$Key] = 0.0;
            } else {
                $Percent[$Key] = sprintf( "%.2f", $read[4] / ($read[1] - $read[4]) * 100.0);
            }
        }

        $query = 'SELECT es.Average_Volume ' .
                 'FROM evaluating_securities es ' .
                 "WHERE es.Symbol = '$read[0]' ";
        $row = DbSingleRowFetch( $query );
        // if no avg vol present or less than 5,000 set volume ratio to zero
        if ( !$row or $row[0] < 5 ) {
            $VolumeRatio = '0';
        } else {
            $VolumeRatio = number_format( $read[8] * 100 / $row[0], 2, ".", "" );
        }

        $Volume[$Key] = $VolumeRatio;
    }

    static $tableHeader = 
                    '<tr>' .
                        '<th>Time</th>' .
                        '<th>Name Abbreviated (Symbol)</th>' .
                        '<th>Last Trade</th>' .
                        '<th>Change</th>' .
                        '<th>Percent<br>Change</th>' .
                        '<th>Opened</th>' .
                        '<th>Day Range<br>Low - High</th>' .
                        '<th>Share Volume</th>' .
                        '<th>Vol vs Avg</th>' .
                    '</tr>';

    // helper function
    // used to display data rows for the three tables
    function display_row( $data ) {
        global $SummaryURL;

        $symbol = $data[0];
        $percent = $data['Percent'] . '%';

        if ( own_this_security( $symbol ) ) {
            $color = ' style="background-color: lightblue;"';
        } elseif ( virtually_own_this_security( $symbol ) ) {
            $color = ' style="background-color: gold;"';
        } else {
            $color = '';
        }

        $gotoSummaryURL = str_replace( '%SYMBOL%', strtoupper( $symbol ), $SummaryURL );

        echo "<tr$color>",
                "<td>$data[3]</td>",      // Time
                '<td style="text-align: left;">' . substr( $data['Name'], 0, 26 ) . " <a href='$gotoSummaryURL' target='sponsor'>($symbol)</a></td>",    // Symbol
                "<td>$$data[1]</td>";              // Last Trade
        if ( substr( $data[4], 0, 1 ) === '-' ) {
            echo "<td style='color: #c00000;'>$data[4]</td>" .    // Change
                 "<td style='color: #c00000;'>$percent</td>";     // Percent Change
        } elseif ( '0.00' == $data[4] or '+0.00' == $data[4] ) {  // allow type juggling
            echo '<td colspan=2 style="text-align: center;">unchanged</td>';
        } else {
            if ( substr( $data[4], 0, 1 ) === '+' ) {
                $data[4] = substr( $data[4], 1 );
            }
            echo "<td>$data[4]</td>" .                          // Change
                 "<td>$percent</td>";                           // Percent Change
        }
        echo    '<td>' . ( is_numeric( $data[5] ) && $data[5] > 0 ? '$' . $data[5] : 'n/a' ) . '</td>',                                    // Opened At
                '<td style="text-align: center;">' .
                                ( is_numeric( $data[7] ) && $data[7] > 0 ? "&nbsp;$$data[7]" : 'n/a' ) .// low
                                '&nbsp;-&nbsp;' .
                                ( is_numeric( $data[6] ) && $data[6] > 0 ? "$$data[6]&nbsp;" : 'n/a' ) . '</td>' .    // high
                 '<td>', number_format( trim($data[8]) ), '</td>',  // Volume
                 '<td>' . $data['Volume'] . '%</td>' .              // volume vs average volume
             '</tr>';
    }


    arsort( $Percent, SORT_NUMERIC );

    // is today a trading/work day?
    $rowDay = DbSingleRowFetch( 'SELECT 1 ' .
                                'FROM calendar ' .
                                'WHERE day = CURRENT_DATE() ' .
                                  "AND Type_Of_Day = 'Business'" );
    $isWorkDay = ( null !== $rowDay );
    if ( $isWorkDay ) {
        $today = date( 'Y-m-d' );
    }


    echo '<table>';

    echo '<tr bgcolor="grey"><td COLSPAN=9 style="text-align: center; color: blue; font-size: large; font-weight: bold;"><br>TOP 10 (by Percent Change)</td></tr>' .
         $tableHeader;

    $line_counter = 0;
    foreach ( $Percent as $Key => $Value )
    {
        // if data is missing for symbol, continue past it
        if ( !isset( $quotes[$Key]['Data'] ) ) {
            continue;
        }

        $read = $quotes[$Key]['Data'];

        // if today is a trading day and the date from last trade is not today then ignore
        if ( $isWorkDay && strncasecmp( $today, $read[2], 10 ) != 0 ) {
            continue;
        }

        // if no volume, get next security
        if ( 0 == $read[8] ) {          // allow type juggling
            continue;
        }

        $read['Name'] = $quotes[$Key]['Name'];
        $read['Percent'] = $Percent[$Key];
        $read['Volume'] = $Volume[$Key];

        display_row( $read );

        if ( 10 <= ++$line_counter ) {
            break;
        }
    }


    echo '<tr bgcolor="grey"><td COLSPAN=9 style="text-align: center; color: gold; font-size: large; font-weight: bold;"><br>BOTTOM 10 (by Percent Change)</td></tr>' .
         $tableHeader;

    $line_counter = 0;
    foreach ( $Percent as $Key => $Value )
    {
        if ( count( $Percent ) - 9 > ++$line_counter ) {
            continue;
        }

        // if data is missing for symbol, continue past it
        if ( !isset( $quotes[$Key]['Data'] ) ) {
            continue;
        }

        $read = $quotes[$Key]['Data'];

        // if today is a trading day and the date from last trade is not today then ignore
        if ( $isWorkDay && strncasecmp( $today, $read[2], 10 ) != 0 ) {
            continue;
        }

        // if no volume, break out of loop
        if ( 0 == $read[8] ) {          // allow type juggling
            continue;
        }

        $read['Name'] = $quotes[$Key]['Name'];
        $read['Percent'] = $Percent[$Key];
        $read['Volume'] = $Volume[$Key];

        display_row( $read );
    }


    arsort( $Volume, SORT_NUMERIC );

    echo '<tr bgcolor="grey"><td COLSPAN=9 style="text-align: center; color: green; font-size: large; font-weight: bold;"><br>TOP 10 (by Volume vs Average Volume)</td></tr>' .
         $tableHeader;

    $line_counter = 0;
    foreach( $Volume as $Key => $Value )
    {
        // if data is missing for symbol, continue past it
        if ( !isset( $quotes[$Key]['Data'] ) ) {
            continue;
        }
        $read = $quotes[$Key]['Data'];

        // if today is a trading day and the date from last trade is not today then ignore
        if ( $isWorkDay && strncasecmp( $today, $read[2], 10 ) != 0 ) {
            continue;
        }
        // if no volume, break out of loop
        if ( 0 == $read[8] ) {          // allow type juggling
            continue;
        }
        $read['Name'] = $quotes[$Key]['Name'];
        $read['Percent'] = $Percent[$Key];
        $read['Volume'] = $Volume[$Key];

        display_row( $read );

        if ( 10 <= ++$line_counter )
            break;
    }
    echo "</TABLE> ";
?>
<br>
Go to <a href='#Top'>Top</a> of page
<p>
Return to <a href='PortfolioTools.php'>Portfolio Tools</a>
<p>
<b>How is this report helpful?</b><BR>
Idea is to use a set of exception based reports to highlight investments that <u>probably</u> require some attention.
It may be an earnings report, news about a competitor, new government regulations, legal case, merger/acquisition news, ..., who knows why?
Prudent thing may be to investigate to see if some change occurred that would alter our reason to hold the investment.
<br>
<p>
For example:  if a company is to be acquired by another, it may be advantageous to take the profit now and redeploy the proceeds into other investment opportunities.
Yes the price today is (probably) still less than the acquisition price but <ul>
<li>could you get a better effective rate of return on another investment
<li>what if the merger should fail?
<li>...
</ul>
Future enhancement to these three exception reports would be to vary the time period.  Some trends take several days to appear.
<hr>
Last Modified the program for this report February 4, 2019
</body>
</html>
