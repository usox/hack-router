<?hh //strict
/*
 *  Copyright (c) 2015-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

namespace Facebook\HackRouter\PrefixMatching;

use type Facebook\HackRouter\PatternParser\{
  LiteralNode,
  Node,
  Parser,
};
use namespace HH\Lib\{C, Dict, Keyset, Str, Vec};

final class PrefixMap<T> {
  public function __construct(
    private dict<string, T> $literals,
    private dict<string, PrefixMap<T>> $prefixes,
    private dict<string, PrefixMapOrResponder<T>> $regexps,
  ) {
  }

  public static function fromFlatMap(
    dict<string, T> $map,
  ): PrefixMap<T> {
    $entries = Vec\map_with_key(
      $map,
      ($pattern, $responder) ==> tuple(
        Parser::parse($pattern)->getChildren(),
        $responder,
      ),
    );

    return self::fromFlatMapImpl($entries);
  }

  private static function fromFlatMapImpl(
    vec<(vec<Node>, T)> $entries,
  ): PrefixMap<T> {
    $literals = dict[];
    $prefixes = vec[];
    $regexps = vec[];
    foreach ($entries as list($nodes, $responder)) {
      $node = C\firstx($nodes);
      $nodes = Vec\drop($nodes, 1);
      if ($node instanceof LiteralNode) {
        if (C\is_empty($nodes)) {
          $literals[$node->getText()] = $responder;
        } else {
          $prefixes[] = tuple($node->getText(), $nodes, $responder);
        }
      } else {
        $regexps[] = tuple('#'.$node->asRegexp('#').'#', $nodes, $responder);
      }
    }

    $by_first = Dict\group_by($prefixes, $entry ==> $entry[0]);
    $grouped = self::groupByCommonPrefix(Keyset\keys($by_first));
    $prefixes = Dict\map_with_key(
      $grouped,
      ($prefix, $keys) ==> Vec\map(
        $keys,
        $key ==> Vec\map(
          $by_first[$key],
          $row ==> {
            list($text, $nodes, $responder) = $row;
            if ($text === $prefix) {
              return tuple($nodes, $responder);
            }
            $suffix = Str\strip_prefix($text, $prefix);
            return tuple(
              Vec\concat(
                vec[new LiteralNode($suffix)],
                $nodes,
              ),
              $responder,
            );
          }
        )
      ) |> Vec\flatten($$) |> self::fromFlatMapImpl($$)
    );

    $by_first = Dict\group_by($regexps, $entry ==> $entry[0]);
    $regexps = Dict\map(
      $by_first,
      $entries ==> $entries
        |> Vec\map(
          $$,
          $entry ==> tuple($entry[1], $entry[2]),
        )
        |> C\count($$) === 1
          ? new PrefixMapOrResponder(null, C\onlyx($$)[1])
          : new PrefixMapOrResponder(self::fromFlatMapImpl($$), null)
    );

    return new self($literals, $prefixes, $regexps);
  }

  public static function groupByCommonPrefix(
    keyset<string> $keys,
  ): dict<string, keyset<string>> {
    $lens = Vec\map($keys, $key ==> Str\length($key));
    $min = min($lens);
    invariant(
      $min !== 0,
      "Shouldn't have 0-length prefixes",
    );
    return $keys
      |> Dict\group_by($$, $key ==> Str\slice($key, 0, $min))
      |> Dict\map($$, $vec ==> keyset($vec));
  }

  public function getSerializable(): mixed where T as string {
    return dict[
      'literals' => $this->literals,
      'prefixes' => Dict\map($this->prefixes, $it ==> $it->getSerializable()),
      'regexps' => Dict\map($this->regexps, $it ==> $it->getSerializable()),
    ] |> Dict\filter($$, $it ==> !C\is_empty($it));
  }
}
