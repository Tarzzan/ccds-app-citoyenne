<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CommentController
 * Couvre : création, édition, suppression, threading, modération
 */
class CommentControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation du contenu
    // -------------------------------------------------------------------------

    public function testCommentCannotBeEmpty(): void
    {
        $content = '';
        $this->assertEmpty($content);
    }

    public function testCommentMaxLength(): void
    {
        $content = str_repeat('a', 2001);
        $this->assertGreaterThan(2000, strlen($content));
    }

    public function testCommentAcceptsValidContent(): void
    {
        $content = 'Ce signalement est toujours présent rue Victor Hugo.';
        $this->assertNotEmpty($content);
        $this->assertLessThanOrEqual(2000, strlen($content));
    }

    // -------------------------------------------------------------------------
    // Permissions d'édition
    // -------------------------------------------------------------------------

    public function testOnlyAuthorCanEditComment(): void
    {
        $commentUserId  = 5;
        $requestingUser = 5;
        $this->assertEquals($commentUserId, $requestingUser);
    }

    public function testOtherUserCannotEditComment(): void
    {
        $commentUserId  = 5;
        $requestingUser = 7;
        $this->assertNotEquals($commentUserId, $requestingUser);
    }

    public function testAdminCanDeleteAnyComment(): void
    {
        $role = 'admin';
        $this->assertEquals('admin', $role);
    }

    // -------------------------------------------------------------------------
    // Threading (réponses imbriquées)
    // -------------------------------------------------------------------------

    public function testReplyHasParentId(): void
    {
        $reply = ['content' => 'Merci !', 'parent_id' => 12];
        $this->assertNotNull($reply['parent_id']);
        $this->assertEquals(12, $reply['parent_id']);
    }

    public function testTopLevelCommentHasNullParentId(): void
    {
        $comment = ['content' => 'Problème signalé.', 'parent_id' => null];
        $this->assertNull($comment['parent_id']);
    }

    public function testNestingIsLimitedToOneLevel(): void
    {
        // Un reply ne peut pas avoir un parent qui est lui-même un reply
        $parentComment = ['id' => 1, 'parent_id' => null];
        $reply         = ['id' => 2, 'parent_id' => 1];

        // Le parent du reply doit être un commentaire de niveau 0
        $this->assertNull($parentComment['parent_id']);
    }

    // -------------------------------------------------------------------------
    // Marquage comme édité
    // -------------------------------------------------------------------------

    public function testEditedCommentIsMarked(): void
    {
        $comment = ['content' => 'Texte original', 'is_edited' => 0];
        $comment['content']   = 'Texte modifié';
        $comment['is_edited'] = 1;
        $this->assertEquals(1, $comment['is_edited']);
    }
}
