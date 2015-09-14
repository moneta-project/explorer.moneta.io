<?php /*
    RPC Ace v0.7.1 (RPC AnyCoin Explorer)

    (c) 2014 Robin Leffmann <djinn at stolendata dot net>

    https://github.com/stolendata/rpc-ace/

    licensed under CC BY-NC-SA 4.0 - http://creativecommons.org/licenses/by-nc-sa/4.0/
*/

define( 'ACEVERSION', '1.1' );

define( 'RPCHOST', '127.0.0.1' );
define( 'RPCPORT', 10332 );
define( 'RPCUSER', 'monetarpc' );
define( 'RPCPASS', 'your_password' );

define( 'COINNAME', 'MONETA' );
define( 'COINPOS', false );

define( 'RETURNJSON', false );
define( 'DATEFORMAT', 'H:i:s Y-M-d' );
define( 'BLOCKSPERLIST', 12 );

// for the example explorer
define( 'COINHOME', './rpcace.php' );
define( 'REFRESHTIME', 180 );

// courtesy of https://github.com/aceat64/EasyBitcoin-PHP/
require_once( 'easybitcoin.php' );

class RPCAce
{
    private static $blockFields = [ 'hash', 'nextblockhash', 'previousblockhash', 'confirmations', 'size', 'height', 'version', 'merkleroot', 'time', 'nonce', 'bits', 'difficulty', 'mint', 'proofhash' ];

    private static function base()
    {
        $rpc = new Bitcoin( RPCUSER, RPCPASS, RPCHOST, RPCPORT );
        $info = $rpc->getinfo();
        if( $rpc->status !== 200 && $rpc->error !== '' )
            return [ 'err'=>'failed to connect - node not reachable, or user/pass incorrect' ];

        $output['rpcace_version'] = ACEVERSION;
        $output['coin_name'] = COINNAME;
        $output['num_blocks'] = $info['blocks'];
        $output['num_connections'] = $info['connections'];

        if( COINPOS === true )
        {
            $output['current_difficulty_pow'] = $info['difficulty']['proof-of-work'];
            $output['current_difficulty_pos'] = $info['difficulty']['proof-of-stake'];
        }
        else
            $output['current_difficulty_pow'] = $info['difficulty'] * 10;

        if( !($hashRate = @$rpc->getmininginfo()['netmhashps']) && !($hashRate = @$rpc->getmininginfo()['networkhashps'] / 1000) )
            $hashRate = $rpc->getnetworkhashps() / 1000;
        $output['hashrate_mhps'] = sprintf( '%.2f', $hashRate );

        return [ 'output'=>$output, 'rpc'=>$rpc ];
    }

    // enumerate block details from hash
    public static function getBlock( $hash )
    {
        if( preg_match('/^([[:xdigit:]]{64})$/', $hash) !== 1 )
            return RETURNJSON ? json_encode( ['err'=>'not a valid block hash'] ) : [ 'err'=>'not a valid block hash' ];

        $base = self::base();
        if( isset($base['err']) )
            return RETURNJSON ? json_encode( $base ) : $base;

        if( ($block = $base['rpc']->getblock($hash)) === false )
            return RETURNJSON ? json_encode( ['err'=>'no block with that hash'] ) : [ 'err'=>'no block with that hash' ];

        $total = 0;
        foreach( $block as $id => $val )
            if( $id === 'tx' )
                foreach( $val as $txid )
                {
                    $transaction = array();
                    $transaction['id'] = $txid;
                    if( ($tx = $base['rpc']->getrawtransaction($txid, 1)) === false )
                        continue;

                    if( isset($tx['vin'][0]['coinbase']) )
                        $transaction['coinbase'] = true;

                    foreach( $tx['vout'] as $entry )
                        if( $entry['value'] > 0.0 )
                        {
                            // nasty number formatting trick that hurts my soul, but it has to be done...
                            $total += ( $transaction['outputs'][$entry['n']]['value'] = rtrim(rtrim(sprintf('%.8f', $entry['value']), '0'), '.') );
                            $transaction['outputs'][$entry['n']]['address'] = $entry['scriptPubKey']['addresses'][0];
                        }
                    $base['output']['transactions'][] = $transaction;
                }
            elseif( in_array($id, self::$blockFields) )
                $base['output']['fields'][$id] = $val;

        $base['output']['total_out'] = $total;
        $base['rpc'] = null;
        return RETURNJSON ? json_encode( $base['output'] ) : $base['output'];
    }

    // create summarized list from block number
    public static function getBlockList( $ofs, $n = BLOCKSPERLIST )
    {
        $base = self::base();
        if( isset($base['err']) )
            return RETURNJSON ? json_encode( $base ) : $base;

        $offset = $ofs === null ? $base['output']['num_blocks'] : abs( (int)$ofs );
        if( $offset > $base['output']['num_blocks'] )
            return RETURNJSON ? json_encode( ['err'=>'block does not exist'] ) : [ 'err'=>'block does not exist' ];

        $i = $offset;
        while( $i >= 0 && $n-- )
        {
            $frame = array();
            $frame['hash'] = $base['rpc']->getblockhash( $i );
            $block = $base['rpc']->getblock( $frame['hash'] );
            $frame['height'] = $block['height'];
            $frame['difficulty'] = $block['difficulty'];
            $frame['time'] = $block['time'];
            $frame['date'] = gmdate( DATEFORMAT, $block['time'] );

            $txCount = 0;
            $valueOut = 0;
            foreach( $block['tx'] as $txid )
            {
                $txCount++;
                if( ($tx = $base['rpc']->getrawtransaction($txid, 1)) === false )
                    continue;
                foreach( $tx['vout'] as $vout )
                    $valueOut += $vout['value'];
            }
            $frame['tx_count'] = $txCount;
            $frame['total_out'] = $valueOut;

            $base['output']['blocks'][] = $frame;
            $i--;
        }

        $base['rpc'] = null;
        return RETURNJSON ? json_encode( $base['output'] ) : $base['output'];
    }
}
?>
<?php
/*
   This is the example block explorer of RPC Ace. If you intend to use just
   the RPCAce class itself to fetch and process the array or JSON output on
   your own, you should remove this entire PHP section.
*/

$query = substr( @$_SERVER['QUERY_STRING'], 0, 64 );

if( strlen($query) == 64 )
    $ace = RPCAce::getBlock( $query );
else
{
    $query = ( $query === false || !is_numeric($query) ) ? null : abs( (int)$query );
    $ace = RPCAce::getBlockList( $query, BLOCKSPERLIST );
    $query = $query === null ? @$ace['num_blocks'] : $query;
}

if( isset($ace['err']) || RETURNJSON === true )
    die( 'RPC Ace error: ' . (RETURNJSON ? $ace : $ace['err']) );

echo <<<END
<!DOCTYPE html>
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="robots" content="index,nofollow,nocache" />

END;

if( empty($query) || ctype_digit($query) )
    echo '<meta http-equiv="refresh" content="' . REFRESHTIME . '; url=' . basename( __FILE__ ) . "\" />\n";
echo '<title>' . COINNAME . ' block explorer &middot; v' . ACEVERSION . "</title>\n";

echo <<<END
<link href='https://fonts.googleapis.com/css?family=Ubuntu&subset=latin,cyrillic-ext' rel='stylesheet' type='text/css'>
<link href="https://fonts.googleapis.com/css?family=Robot" rel="stylesheet" type="text/css" />
<style type="text/css">
html { background-color: #FFF;
       color: #303030;
       font-family: Ubuntu, sans-serif;
       font-size: 14px;
       white-space: pre; }
a { color: #ff6600; }
div.mid { width: 900px;
          margin: 2% auto; }
td { width: 16%; }
td.urgh { width: 100%; }
td.key { text-align: right; }
td.value { padding-left: 16px; width: 100%; }
tr.illu:hover { background-color: #c8c8c8; }
</style>
</head>
<body>
<div class="mid">
END;

// header
echo '<table><tr><td class="urgh"><b><a href="' . COINHOME . '" >' . COINNAME . '</a></b> block explorer</td><td>Blocks:</td><td><a href="?' . $ace['num_blocks'] . '">' . $ace['num_blocks'] . '</a>';
$diffNom = 'Difficulty';
$diff = sprintf( '%.3f', $ace['current_difficulty_pow'] );
if( COINPOS )
{
    $diffNom .= ' &middot; PoS';
    $diff .= ' &middot;' . sprintf( '%.1f', $ace['current_difficulty_pos'] );
}
echo "<tr><td></td><td>$diffNom:</td><td>$diff</td></tr>";

// list of blocks
if( isset($ace['blocks']) )
{
    echo "<table><tr><td><b>Block</b></td><td><b>Hash</b></td><td><b>$diffNom</b></td><td><b>Time (UTC)</b></td><td><b>Tx# &middot; Value out</b></td></tr><tr><td colspan=\"5\"></td></tr>";
    foreach( $ace['blocks'] as $block )
        echo "<tr class=\"illu\"><td>{$block['height']}</td><td><a href=\"?{$block['hash']}\">" . substr( $block['hash'], 0, 16 ) . ' ...</a></td><td>' . sprintf( '%.2f', $block['difficulty'] ) . "</td><td><a title=\"{$block['time']}\">{$block['date']}</a></td><td>{$block['tx_count']} &middot; " . sprintf( '%.2f', $block['total_out'] ) . '</td></tr>';

    $newer = $query < $ace['num_blocks'] ? '<a href="?' . ( $ace['num_blocks'] - $query >= BLOCKSPERLIST ? $query + BLOCKSPERLIST : $ace['num_blocks'] ) . '">&lt; Newer</a>' : '&lt; Newer';
    $older = $query - count( $ace['blocks'] ) >= 0 ? '<a href="?' . ( $query - BLOCKSPERLIST ) . '">Older &gt;</a>' : 'Older &gt;';

    echo "<tr><td colspan=\"5\" class=\"urgh\"> </td></tr><tr><td colspan=\"5\">$newer          $older</td></tr></table>";
}
// block details
elseif( isset($ace['transactions']) )
{
    echo '<table>';
    foreach( $ace['fields'] as $field => $val )
        if( $field == 'previousblockhash' || $field == 'nextblockhash' )
            echo "<tr><td class=\"key\">$field</td><td class=\"value\"><a href=\"?$val\">$val</a></td></tr>";
        else
            echo "<tr><td class=\"key\">$field</td><td class=\"value\">$val</td></tr>";

    foreach( $ace['transactions'] as $tx )
    {
        echo "<tr><td class=\"key\">tx</td><td class=\"value\">{$tx['id']}</td></tr>";
        foreach( $tx['outputs'] as $output )
            echo '<tr><td></td><td class="value">     ' . $output['value'] . ( isset( $tx['coinbase'] ) ? '*' : '' ) . " -&gt; {$output['address']}</td></tr>";
    }

    echo'</table>';
}
echo '<table><tr><td>Powered by <a href="http://moneta.io/" target="_blank">Moneta.io</a> v' . ACEVERSION . ' </td><td>Network hashrate: </td><td>' . $ace['hashrate_mhps'] . ' MH/s</td></tr><tr><td> </td><td></td><td></td></tr></table>';
echo '</div></body></html>'
?>
